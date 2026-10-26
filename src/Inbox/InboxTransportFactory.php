<?php

declare(strict_types=1);

namespace App\Inbox;

use Doctrine\DBAL\Connection;
use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @implements TransportFactoryInterface<InboxTransport>
 */
final readonly class InboxTransportFactory implements TransportFactoryInterface
{
    private const string DSN = 'inbox://';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(
        #[SensitiveParameter] string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        return new InboxTransport($this->connection, $serializer, $this->clock);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[SensitiveParameter] string $dsn, array $options): bool
    {
        return $dsn === self::DSN;
    }
}
