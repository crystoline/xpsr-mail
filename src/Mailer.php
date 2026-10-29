<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail;

use Crystoline\Xpsr\View\ViewHandlerInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends email, now or from a queue worker:
 *
 *     $email = $mailer->compose('emails.welcome', ['user' => $user])   // views/emails/welcome.twig
 *         ->to($user->email)
 *         ->subject('Welcome');
 *     $mailer->send($email);      // now
 *     $mailer->queue($email);     // later, from php xpsr queue:work (crystoline/xpsr-queue)
 *
 * Messages are Symfony Mime Email objects. Without a From, they get mail.from.
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly MailOptions $options,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * An email whose HTML body is a view, rendered now with $data, so a queued email carries its
     * finished content. The text body is $textView when given, otherwise the HTML without tags.
     *
     * @param array<string, mixed> $data
     */
    public function compose(string $view, array $data = [], ?string $textView = null): Email
    {
        $views = $this->container->get(ViewHandlerInterface::class);
        $html = $views->render($view, $data);

        return (new Email())
            ->html($html)
            ->text($textView === null ? self::text($html) : $views->render($textView, $data));
    }

    public function send(Email $email): void
    {
        $this->mailer->send($this->withDefaults($email));
    }

    /**
     * Send from a queue worker, after an optional delay ("10 minutes" or seconds), on mail.queue or
     * another transport.
     */
    public function queue(Email $email, string|int|null $delay = null, ?string $queue = null): void
    {
        if (!interface_exists(\Crystoline\Xpsr\Queue\QueueInterface::class) || !$this->container->has(\Crystoline\Xpsr\Queue\QueueInterface::class)) {
            throw new LogicException('Queued email needs crystoline/xpsr-queue: run composer require crystoline/xpsr-queue, and add Crystoline\Xpsr\Queue\QueueServiceRegistry to app.registry.');
        }
        $this->container->get(\Crystoline\Xpsr\Queue\QueueInterface::class)
            ->dispatch(new SendMail($this->withDefaults($email)), $delay, $queue ?? $this->options->queue);
    }

    private function withDefaults(Email $email): Email
    {
        if ($email->getFrom() === [] && $this->options->from !== null) {
            $email->from($this->options->from);
        }

        return $email;
    }

    /** A readable text version of an HTML body. */
    private static function text(string $html): string
    {
        $text = preg_replace(['#<(script|style)\b.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i', '#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is'], ['', "\n", "\n\n", '$2 ($1)'], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace(["/[ \t]+/", "/\n[ \t]+/", "/\n{3,}/"], [' ', "\n", "\n\n"], $text));
    }
}
