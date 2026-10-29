<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Mail\Console\MailTestCommand;
use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Mail\MailOptions;
use Crystoline\Xpsr\Mail\MailServiceRegistry;
use Crystoline\Xpsr\Mail\SendMail;
use Crystoline\Xpsr\Mail\Tests\Fixtures\MemoryLogger;
use Crystoline\Xpsr\Mail\Transport\ArrayTransport;
use Crystoline\Xpsr\Queue\Console\QueueWorkCommand;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The mailer on the array:// and log:// transports, view templates, queued email, and mail:test.
 */
final class MailTest extends TestCase
{
    private string $root;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-mail-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/views/emails', 0777, true);
        file_put_contents($this->root . '/views/emails/welcome.twig', <<<'TWIG'
            <style>p { color: #333 }</style>
            <h1>Welcome, {{ name }}</h1>
            <p>Your account is ready.<br>Sign in at <a href="https://example.com/login">our site</a>.</p>
            <p>Tom &amp; Jerry</p>
            TWIG);
        file_put_contents($this->root . '/views/emails/welcome_text.twig', 'Welcome, {{ name }}. Sign in at https://example.com/login');
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testEmailWithoutAFromGetsTheConfiguredSender(): void
    {
        $application = $this->application(['dsn' => 'array://default', 'from' => ['address' => 'hello@example.com', 'name' => 'Example']]);
        $mailer = $application->get(Mailer::class);
        $mailer->send((new Email())->to('ada@example.com')->subject('Hi')->text('Hello.'));
        $mailer->send((new Email())->from('billing@example.com')->to('ada@example.com')->subject('Invoice')->text('Attached.'));

        $sent = $application->get(ArrayTransport::class)->emails();
        self::assertCount(2, $sent);
        self::assertEquals([new Address('hello@example.com', 'Example')], $sent[0]->getFrom());
        self::assertEquals([new Address('billing@example.com')], $sent[1]->getFrom(), 'A message\'s own From is kept.');
        self::assertSame('Hi', $sent[0]->getSubject());

        $messages = $application->get(ArrayTransport::class)->messages();
        self::assertSame(['ada@example.com'], array_map(static fn(Address $address): string => $address->getAddress(), $messages[0]->getEnvelope()->getRecipients()));

        $application->get(ArrayTransport::class)->clear();
        self::assertSame([], $application->get(ArrayTransport::class)->emails());
    }

    public function testComposeRendersTheViewAndATextVersion(): void
    {
        $mailer = $this->application(['dsn' => 'array://default'])->get(Mailer::class);

        $email = $mailer->compose('emails.welcome', ['name' => '<Ada>']);
        self::assertStringContainsString('<h1>Welcome, &lt;Ada&gt;</h1>', (string) $email->getHtmlBody(), 'Views escape data as usual.');
        self::assertSame(
            "Welcome, <Ada>\n\nYour account is ready.\nSign in at our site (https://example.com/login).\n\nTom & Jerry",
            $email->getTextBody(),
            'Without a text view: the HTML as readable text, without the style block.',
        );

        $email = $mailer->compose('emails.welcome', ['name' => 'Ada'], 'emails.welcome_text');
        self::assertSame('Welcome, Ada. Sign in at https://example.com/login', $email->getTextBody());
    }

    public function testTheLogTransportWritesEmailToTheApplicationLog(): void
    {
        $application = $this->application(['from' => 'hello@example.com']);
        self::assertSame('log://default', (string) $application->get(TransportInterface::class), 'log:// is the default.');

        $application->get(Mailer::class)->send((new Email())->to('ada@example.com', 'grace@example.com')->subject('Hi')->text('Hello.'));
        self::assertCount(1, $this->logger->records);
        self::assertSame('Email to {to}: {subject}', $this->logger->records[0]['message']);
        self::assertSame(
            ['to' => 'ada@example.com, grace@example.com', 'from' => 'hello@example.com', 'subject' => 'Hi', 'text' => 'Hello.'],
            $this->logger->records[0]['context'],
        );
    }

    public function testQueuedEmailIsSentByTheWorker(): void
    {
        $application = $this->application(
            ['dsn' => 'array://default', 'from' => 'hello@example.com', 'queue' => 'mail'],
            ['transports' => ['default' => ['dsn' => 'sync://'], 'mail' => ['dsn' => 'in-memory://?serialize=true']], 'failed' => false],
        );
        $email = $application->get(Mailer::class)->compose('emails.welcome', ['name' => 'Ada'])->to('ada@example.com')->subject('Welcome');
        $application->get(Mailer::class)->queue($email);
        self::assertCount(0, $application->get(ArrayTransport::class)->emails(), 'Not sent yet.');

        $worker = new CommandTester($application->get(QueueWorkCommand::class));
        self::assertSame(Command::SUCCESS, $worker->execute(['transports' => ['mail'], '--stop-when-empty' => true]), $worker->getDisplay());
        self::assertStringContainsString('Done ' . SendMail::class . ' (mail)', $worker->getDisplay());
        $sent = $application->get(ArrayTransport::class)->emails();
        self::assertCount(1, $sent);
        self::assertSame('Welcome', $sent[0]->getSubject());
        self::assertEquals([new Address('hello@example.com')], $sent[0]->getFrom());
        self::assertStringContainsString('Welcome, Ada', (string) $sent[0]->getHtmlBody(), 'The body was rendered before queueing.');

        $application->get(Mailer::class)->queue((new Email())->to('ada@example.com')->subject('Now')->text('Sync.'), queue: 'default');
        self::assertCount(2, $application->get(ArrayTransport::class)->emails(), 'queue: picks another transport.');
    }

    public function testQueueingWithoutTheQueueProviderExplainsWhatToAdd(): void
    {
        $mailer = $this->application(['dsn' => 'array://default'])->get(Mailer::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Queued email needs crystoline/xpsr-queue');
        $mailer->queue((new Email())->to('ada@example.com')->text('Hello.'));
    }

    public function testMailTestSendsAnEmail(): void
    {
        $application = $this->application(['dsn' => 'array://default', 'from' => 'hello@example.com']);
        self::assertContains('mail:test', array_keys($application->get(CommandRegistry::class)->all()));

        $tester = new CommandTester($application->get(MailTestCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['to' => 'ada@example.com', '--subject' => 'Check']));
        self::assertSame("Sent a test email to ada@example.com through array://default.\n", $tester->getDisplay());
        $sent = $application->get(ArrayTransport::class)->emails();
        self::assertSame('Check', $sent[0]->getSubject());
        self::assertSame('ada@example.com', $sent[0]->getTo()[0]->getAddress());

        self::assertSame(Command::INVALID, $tester->execute(['to' => 'not an address']));
        self::assertStringContainsString('"not an address" is not an email address.', $tester->getDisplay());
        self::assertCount(1, $application->get(ArrayTransport::class)->emails());
    }

    public function testAnUnsupportedTransportNamesTheDsnOption(): void
    {
        $application = $this->application(['dsn' => 'carrier-pigeon://default']);

        try {
            $application->get(Mailer::class);
            self::fail('The DSN was accepted.');
        } catch (ContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertStringStartsWith('mail.dsn: The "carrier-pigeon" scheme is not supported', $previous->getMessage());
        }
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        MailOptions::fromArray($config);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['driver' => 'smtp'], 'Unknown option mail.driver. Known options: dsn, from, queue.'];
        yield 'not a DSN' => [['dsn' => 'smtp.example.com'], 'mail.dsn must be a DSN'];
        yield 'bad from' => [['from' => 'nobody'], 'mail.from must be an email address'];
        yield 'from without address' => [['from' => ['name' => 'Example']], 'mail.from must be an email address'];
        yield 'empty queue' => [['queue' => ''], 'mail.queue must name a transport'];
    }

    public function testOptionsHaveDefaults(): void
    {
        $options = MailOptions::fromArray([]);
        self::assertSame('log://default', $options->dsn);
        self::assertNull($options->from);
        self::assertNull($options->queue);
        self::assertEquals(new Address('a@example.com', 'A'), MailOptions::fromArray(['from' => ['address' => 'a@example.com', 'name' => 'A']])->from);
    }

    /**
     * @param array<string, mixed> $mail
     * @param array<string, mixed>|null $queue
     */
    private function application(array $mail, ?array $queue = null): Application
    {
        $application = new Application($this->root);
        $config = $application->getConfig();
        $config->set('app.registry', [
            ConsoleServiceRegistry::class,
            ViewServiceRegistry::class,
            MailServiceRegistry::class,
            ...($queue === null ? [] : [QueueServiceRegistry::class]),
        ]);
        $config->set('app.view_dir', $this->root . '/views');
        $config->set('mail', $mail);
        if ($queue !== null) {
            $config->set('queue', $queue);
        }
        $application->getContainer()->instance(LoggerInterface::class, $this->logger);
        $application->boot();

        return $application;
    }
}
