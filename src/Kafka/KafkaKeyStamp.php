<?php

declare(strict_types=1);

namespace App\Kafka;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class KafkaKeyStamp implements NonSendableStampInterface
{
    public function __construct(
        public string $key,
    ) {
    }
}
