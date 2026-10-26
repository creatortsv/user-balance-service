<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Validator\Constraints as Assert;

#[AsMessage(serializedTypeName: 'balance.release')]
final readonly class ReleaseMessage implements OperationMessage
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $operationId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $holdId,
    ) {
    }
}
