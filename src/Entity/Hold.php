<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HoldStatus;
use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use BcMath\Number;

final readonly class Hold
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $currency,
        public Number $amount,
        public HoldStatus $status = HoldStatus::Active,
    ) {
    }

    public function capture(): self
    {
        return $this->finish(HoldStatus::Captured);
    }

    public function release(): self
    {
        return $this->finish(HoldStatus::Released);
    }

    private function finish(HoldStatus $status): self
    {
        if ($this->status !== HoldStatus::Active) {
            throw new OperationRejectedException(RejectionReason::HoldNotActive);
        }

        return new self(
            $this->id,
            $this->userId,
            $this->currency,
            $this->amount,
            $status,
        );
    }
}
