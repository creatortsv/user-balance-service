<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\RejectionReason;
use RuntimeException;

final class OperationRejectedException extends RuntimeException
{
    public function __construct(
        public readonly RejectionReason $reason,
    ) {
        parent::__construct(sprintf('The operation is rejected: %s.', $reason->value));
    }
}
