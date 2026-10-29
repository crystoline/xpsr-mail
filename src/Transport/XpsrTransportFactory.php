<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Mail\Transport;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The log:// and array:// transports, next to Symfony's own and the installed provider bridges.
 *
 * @internal Used by MailServiceRegistry.
 */
final class XpsrTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly LogTransport $log, private readonly ArrayTransport $array) {}

    public function create(Dsn $dsn): TransportInterface
    {
        return $dsn->getScheme() === 'log' ? $this->log : $this->array;
    }

    public function supports(Dsn $dsn): bool
    {
        return in_array($dsn->getScheme(), ['log', 'array'], true);
    }
}
