<?php

declare(strict_types=1);

namespace App\Message;

use App\Serializer\InCurrency;
use App\Validator\Amount;
use App\Validator\SupportedCurrency;
use BcMath\Number;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Validator\Constraints as Assert;

#[AsMessage(serializedTypeName: 'balance.transfer')]
final readonly class TransferMessage implements OperationMessage, InCurrency
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $operationId,
        #[Assert\Positive]
        public int $fromUserId,
        #[Assert\Positive]
        #[Assert\NotEqualTo(propertyPath: 'fromUserId')]
        public int $toUserId,
        #[SupportedCurrency]
        public string $currency,
        #[Amount]
        public Number $amount,
    ) {
    }
}
