<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail;

use Crystoline\Xpsr\Queue\JobInterface;
use Symfony\Component\Mime\Email;

/**
 * The queue job Mailer::queue() dispatches: it carries the finished email and sends it from the
 * worker. Needs crystoline/xpsr-queue.
 */
final class SendMail implements JobInterface
{
    public function __construct(public readonly Email $email) {}

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->email);
    }
}
