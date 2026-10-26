<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class EventSerializer implements SerializerInterface
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.symfony_serializer')]
        private SerializerInterface $serializer,
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        return $this->serializer->decode($encodedEnvelope);
    }

    public function encode(Envelope $envelope): array
    {
        return $this->serializer->encode(new Envelope($envelope->getMessage()));
    }
}
