<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Event\HoldReleasedEvent;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\Service\OperationRunner;
use App\UseCase\Dto\ReleaseHoldDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ReleaseHold
{
    public function __construct(
        private OperationRunner $operationRunner,
        private HoldService $holds,
        private LedgerService $ledger,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function execute(ReleaseHoldDto $input): void
    {
        $this->operationRunner->run($input->operationId, function () use ($input): void {
            $hold = $this->holds->release($input->operationId, $input->holdId);

            $this->eventBus->dispatch(
                new HoldReleasedEvent(
                    $input->operationId,
                    $hold->id,
                    $hold->amount,
                    $hold->currency,
                    $this->ledger->balance($hold->userId, $hold->currency),
                    $this->clock->now(),
                ),
            );
        });
    }
}
