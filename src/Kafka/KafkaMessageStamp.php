<?php

declare(strict_types=1);

namespace App\Kafka;

use RdKafka\Message;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class KafkaMessageStamp implements NonSendableStampInterface
{
    public function __construct(
        public Message $message,
    ) {
    }
}
