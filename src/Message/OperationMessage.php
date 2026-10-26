<?php

declare(strict_types=1);

namespace App\Message;

interface OperationMessage
{
    public string $operationId { get; }
}
