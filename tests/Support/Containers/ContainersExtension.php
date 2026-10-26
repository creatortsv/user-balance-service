<?php

declare(strict_types=1);

namespace Tests\Support\Containers;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final readonly class ContainersExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $containers = new Containers();

        $facade->registerSubscribers(
            new PrepareContainersForKernelTest($containers),
            new StopContainersWhenExecutionFinished($containers),
        );
    }
}
