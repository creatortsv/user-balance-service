<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'outbox')]
interface OperationEvent
{
    public function key(): string;
}
