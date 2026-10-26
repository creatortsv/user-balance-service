<?php

declare(strict_types=1);

namespace App\Kafka;

use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @implements TransportFactoryInterface<KafkaTransport>
 */
final readonly class KafkaTransportFactory implements TransportFactoryInterface
{
    private const string SCHEME = 'kafka://';

    public function __construct(
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
        $topic = $options['topic'] ?? null;
        $groupId = $options['group_id'] ?? null;

        if (!is_string($topic) || !(is_string($groupId) || $groupId === null)) {
            throw new InvalidArgumentException(
                'The Kafka transport needs a "topic" option and an optional "group_id".',
            );
        }

        return new KafkaTransport(substr($dsn, strlen(self::SCHEME)), $topic, $groupId, $serializer, $this->clock);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
