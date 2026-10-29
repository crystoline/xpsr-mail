# crystoline/xpsr-mail

Email for XPSR applications, on [Symfony Mailer](https://symfony.com/doc/current/mailer.html). One DSN says where email goes; bodies are views; and with `crystoline/xpsr-queue`, email can be sent from a worker instead of during the request.

```sh
composer require crystoline/xpsr-mail
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Mail\MailServiceRegistry::class,
```

and create `config/mail.php`:

```php
return [
    'dsn' => env('MAIL_DSN', 'log://default'),
    'from' => ['address' => env('MAIL_FROM', 'hello@example.com'), 'name' => env('APP_NAME', 'XPSR')],
    // 'queue' => 'mail',   // the transport Mailer::queue() uses; without it, queue.default
];
```

Check the settings with `php xpsr mail:test you@example.com`.

## Sending

```php
use Crystoline\Xpsr\Mail\Mailer;
use Symfony\Component\Mime\Email;

$mailer->send(
    (new Email())
        ->to('ada@example.com')
        ->subject('Your invoice')
        ->text('Your invoice is attached.')
        ->attach($pdf, 'invoice.pdf', 'application/pdf'),
);
```

Messages are [Symfony Mime](https://symfony.com/doc/current/mailer.html#creating-sending-messages) `Email` objects: `to()`, `cc()`, `bcc()`, `replyTo()`, `priority()`, `attach()`, `embed()`, and the rest. A message without a From gets `mail.from`.

## Views

`compose()` renders a view as the HTML body, with the view layer's usual escaping:

```php
$email = $mailer->compose('emails.welcome', ['user' => $user])   // views/emails/welcome.twig
    ->to($user->email)
    ->subject('Welcome');
$mailer->send($email);
```

The text body is the HTML as readable text: tags removed, `<style>` and `<script>` dropped, and links written as `text (url)`. For a text body of your own, name its view too: `compose('emails.welcome', $data, 'emails.welcome_text')`.

## Queued email

With [`crystoline/xpsr-queue`](../queue/README.md) installed and its provider in `app.registry`, `queue()` sends from a worker, so a slow mail server does not slow the request:

```php
$mailer->queue($email);
$mailer->queue($email, delay: '10 minutes');
$mailer->queue($email, queue: 'bulk');      // a transport from config/queue.php
```

The email is complete when it is queued: views are rendered first, and the worker only sends it. Attach content (`attach($bytes, ...)`) rather than file paths for queued email, because the worker may run where the file is not.

## Transports

| `MAIL_DSN` | Email goes | Needs |
|---|---|---|
| `log://default` (the default) | to the application log, not sent | — |
| `array://default` | into memory, for tests | — |
| `smtp://user:pass@smtp.example.com:587` | to an SMTP server; `smtps://` for TLS from the start | — |
| `sendmail://default` | to the local sendmail binary | — |
| `null://null` | nowhere | — |
| `failover(smtp://a smtp://b)`, `roundrobin(...)` | through several transports | — |
| `ses+smtp://...`, `mailgun+api://...`, `postmark+api://...`, and others | to a provider | its Symfony bridge, such as `symfony/mailgun-mailer` |

Characters such as `@` or `/` in a username or password must be URL-encoded. The [Symfony Mailer documentation](https://symfony.com/doc/current/mailer.html#transport-setup) lists every provider and its DSN.

## Testing

Set `MAIL_DSN=array://default` in `phpunit.xml`, then read what was sent:

```php
use Crystoline\Xpsr\Mail\Transport\ArrayTransport;

$this->postJson('/users', ['email' => 'ada@example.com']);

$sent = $this->application()->get(ArrayTransport::class)->emails();
self::assertCount(1, $sent);
self::assertSame('Welcome', $sent[0]->getSubject());
self::assertSame('ada@example.com', $sent[0]->getTo()[0]->getAddress());
```

`messages()` also gives each message's envelope, and `clear()` empties it. Queued email reaches it when the queue runs: with `QUEUE_DSN=sync://`, at once.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Mail\Mailer` | `compose()`, `send()`, and `queue()`. Shared. |
| `Symfony\Component\Mailer\MailerInterface` | Symfony's mailer, which sends at once. |
| `Symfony\Component\Mailer\Transport\TransportInterface` | The transport `mail.dsn` names. |
| `Crystoline\Xpsr\Mail\Transport\ArrayTransport` | The email sent through `array://`. |

Requires PHP 8.4+.
