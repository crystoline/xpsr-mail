<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail\Transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/**
 * log://default: writes each email to the application log instead of sending it, for development.
 */
final class LogTransport extends AbstractTransport
{
    public function __construct(private readonly LoggerInterface $mailLog = new NullLogger())
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $subject = $original instanceof Message ? $original->getHeaders()->getHeaderBody('Subject') : null;
        $text = $original instanceof Email ? $original->getTextBody() : null;
        $this->mailLog->info('Email to {to}: {subject}', [
            'to' => implode(', ', array_map(static fn(Address $address): string => $address->getAddress(), $message->getEnvelope()->getRecipients())),
            'from' => $message->getEnvelope()->getSender()->getAddress(),
            'subject' => is_string($subject) ? $subject : '',
            'text' => is_string($text) ? $text : '',
        ]);
    }

    public function __toString(): string
    {
        return 'log://default';
    }
}
