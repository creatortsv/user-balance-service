<?php

declare(strict_types=1);

namespace App\UseCase\Dto;

final readonly class CaptureHoldDto
{
    public function __construct(
        public string $operationId,
        public string $holdId,
    ) {
    }
}
