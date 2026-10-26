<?php

declare(strict_types=1);

namespace App\Message;

use App\Serializer\InCurrency;
use App\Validator\Amount;
use App\Validator\SupportedCurrency;
use BcMath\Number;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Validator\Constraints as Assert;

#[AsMessage(serializedTypeName: 'balance.debit')]
final readonly class DebitMessage implements OperationMessage, InCurrency
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $operationId,
        #[Assert\Positive]
        public int $userId,
        #[SupportedCurrency]
        public string $currency,
        #[Amount]
        public Number $amount,
    ) {
    }
}
