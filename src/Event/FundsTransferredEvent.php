<?php

declare(strict_types=1);

namespace App\Event;

use App\Serializer\InCurrency;
use App\ValueObject\Balance;
use BcMath\Number;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(serializedTypeName: 'balance.transferred')]
final readonly class FundsTransferredEvent implements OperationEvent, InCurrency
{
    public function __construct(
        public string $operationId,
        public Number $amount,
        public string $currency,
        public Balance $from,
        public Balance $to,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public function key(): string
    {
        return (string) $this->from->userId;
    }
}
