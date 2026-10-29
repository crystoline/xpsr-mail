<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail\Transport;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * array://default: keeps sent email in memory, for tests.
 *
 *     $sent = $this->application()->get(ArrayTransport::class)->emails();
 *     self::assertSame('Welcome', $sent[0]->getSubject());
 */
final class ArrayTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    private array $messages = [];

    protected function doSend(SentMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<Email> the emails sent so far, oldest first */
    public function emails(): array
    {
        $emails = [];
        foreach ($this->messages as $message) {
            $original = $message->getOriginalMessage();
            if ($original instanceof Email) {
                $emails[] = $original;
            }
        }

        return $emails;
    }

    /** @return list<SentMessage> everything sent so far, raw messages too, with their envelopes */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function __toString(): string
    {
        return 'array://default';
    }
}
