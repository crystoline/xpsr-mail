<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Mail\Console\MailTestCommand;
use Crystoline\Xpsr\Mail\Transport\ArrayTransport;
use Crystoline\Xpsr\Mail\Transport\LogTransport;
use Crystoline\Xpsr\Mail\Transport\XpsrTransportFactory;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers email, configured in config/mail.php:
 *
 *   Crystoline\Xpsr\Mail\Mailer                         compose(), send(), and queue()
 *   Symfony\Component\Mailer\MailerInterface            Symfony's mailer, sending now
 *   Symfony\Component\Mailer\Transport\TransportInterface the transport mail.dsn names
 *   Crystoline\Xpsr\Mail\Transport\ArrayTransport       the emails sent through array://, for tests
 *
 * and mail:test. Queued email also needs Crystoline\Xpsr\Queue\QueueServiceRegistry.
 */
final class MailServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(MailOptions::class, static function (ContainerInterface $container): MailOptions {
            $config = $container->get(Configuration::class)->get('mail', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The mail configuration must be an array.');
            }

            return MailOptions::fromArray($config);
        }, shared: true);
        $this->container->singleton(ArrayTransport::class);
        $this->container->factory(LogTransport::class, static fn(ContainerInterface $container): LogTransport => new LogTransport(
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger(),
        ), shared: true);
        $this->container->factory(TransportInterface::class, static function (ContainerInterface $container): TransportInterface {
            $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
            $factories = [
                new XpsrTransportFactory($container->get(LogTransport::class), $container->get(ArrayTransport::class)),
                ...Transport::getDefaultFactories(null, null, $logger),
            ];
            try {
                return (new Transport($factories))->fromString($container->get(MailOptions::class)->dsn);
            } catch (UnsupportedSchemeException $e) {
                throw new InvalidArgumentException("mail.dsn: {$e->getMessage()} XPSR adds log:// and array://.", previous: $e);
            }
        }, shared: true);
        $this->container->factory(MailerInterface::class, static fn(ContainerInterface $container): MailerInterface => new SymfonyMailer(
            $container->get(TransportInterface::class),
        ), shared: true);
        $this->container->factory(Mailer::class, static fn(ContainerInterface $container): Mailer => new Mailer(
            $container->get(MailerInterface::class),
            $container->get(MailOptions::class),
            $container,
        ), shared: true);
        $this->container->bind(MailTestCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('mail:test', MailTestCommand::class, 'Send a test email.');
        }
    }
}
