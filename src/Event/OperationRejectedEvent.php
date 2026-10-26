<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\RejectionReason;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(serializedTypeName: 'balance.rejected')]
final readonly class OperationRejectedEvent implements OperationEvent
{
    public function __construct(
        public string $operationId,
        public RejectionReason $reason,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public function key(): string
    {
        return $this->operationId;
    }
}
