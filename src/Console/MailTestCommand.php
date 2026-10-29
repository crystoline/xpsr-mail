<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail\Console;

use Crystoline\Xpsr\Mail\Mailer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends a short email now, to check the mail.dsn settings.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MailTestCommand extends Command
{
    public function __construct(private readonly Mailer $mailer, private readonly TransportInterface $transport)
    {
        parent::__construct('mail:test');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Send a test email.')
            ->addArgument('to', InputArgument::REQUIRED, 'The address to send it to')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'The subject', 'XPSR test email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $io->getErrorStyle()->error("\"{$to}\" is not an email address.");

            return self::INVALID;
        }
        $email = (new Email())
            ->to($to)
            ->subject((string) $input->getOption('subject'))
            ->text("This test email was sent by php xpsr mail:test through {$this->transport}.");
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $io->getErrorStyle()->error("Sending through {$this->transport} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $output->writeln("Sent a test email to {$to} through {$this->transport}.");

        return self::SUCCESS;
    }
}
