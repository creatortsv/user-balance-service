<?php

declare(strict_types=1);

namespace Tests\Support\Containers;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

final readonly class StopContainersWhenExecutionFinished implements ExecutionFinishedSubscriber
{
    public function __construct(
        private Containers $containers,
    ) {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->containers->stop();
    }
}
