<?php

declare(strict_types=1);

namespace App\Outbox;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class OutboxMessageStamp implements NonSendableStampInterface
{
    public function __construct(
        public int $id,
    ) {
    }
}
