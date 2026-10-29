<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail;

use InvalidArgumentException;
use Symfony\Component\Mime\Address;

/**
 * The "mail" configuration (config/mail.php), validated:
 *
 *   dsn     where email goes: smtp://user:pass@host:587, sendmail://default, a provider bridge
 *           such as mailgun+api://KEY:DOMAIN@default, log://default (the default: written to the
 *           application log), array://default (kept in memory, for tests), or null://null
 *   from    the sender for messages without one: ['address' => ..., 'name' => ...]
 *   queue   the transport Mailer::queue() uses (default: queue.default)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MailOptions
{
    private function __construct(
        public readonly string $dsn,
        public readonly ?Address $from,
        public readonly ?string $queue,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['dsn', 'from', 'queue'], true)) {
                throw new InvalidArgumentException("Unknown option mail.{$key}. Known options: dsn, from, queue.");
            }
        }
        $dsn = $config['dsn'] ?? 'log://default';
        if (!is_string($dsn) || !str_contains($dsn, '://')) {
            throw new InvalidArgumentException('mail.dsn must be a DSN such as smtp://user:pass@smtp.example.com:587 or log://default.');
        }
        $from = $config['from'] ?? null;
        $address = null;
        if ($from !== null) {
            $email = is_array($from) ? ($from['address'] ?? null) : $from;
            $name = is_array($from) ? ($from['name'] ?? '') : '';
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($name)) {
                throw new InvalidArgumentException('mail.from must be an email address, or [\'address\' => ..., \'name\' => ...].');
            }
            $address = new Address($email, $name);
        }
        $queue = $config['queue'] ?? null;
        if ($queue !== null && (!is_string($queue) || $queue === '')) {
            throw new InvalidArgumentException('mail.queue must name a transport in queue.transports, or be null for queue.default.');
        }

        return new self($dsn, $address, $queue);
    }
}
