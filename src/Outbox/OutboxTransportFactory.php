<?php

declare(strict_types=1);

namespace App\Outbox;

use Doctrine\DBAL\Connection;
use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @implements TransportFactoryInterface<OutboxTransport>
 */
final readonly class OutboxTransportFactory implements TransportFactoryInterface
{
    private const string DSN = 'outbox://';

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
        return new OutboxTransport($this->connection, $serializer, $this->clock);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[SensitiveParameter] string $dsn, array $options): bool
    {
        return $dsn === self::DSN;
    }
}
