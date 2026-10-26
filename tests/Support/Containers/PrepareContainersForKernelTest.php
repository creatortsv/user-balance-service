<?php

declare(strict_types=1);

namespace Tests\Support\Containers;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final readonly class PrepareContainersForKernelTest implements PreparationStartedSubscriber
{
    public function __construct(
        private Containers $containers,
    ) {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod && is_subclass_of($test->className(), KernelTestCase::class)) {
            $this->containers->prepare();
        }
    }
}
