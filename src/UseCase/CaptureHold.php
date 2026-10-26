<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Event\HoldCapturedEvent;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\Service\OperationRunner;
use App\UseCase\Dto\CaptureHoldDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CaptureHold
{
    public function __construct(
        private OperationRunner $operationRunner,
        private HoldService $holds,
        private LedgerService $ledger,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function execute(CaptureHoldDto $input): void
    {
        $this->operationRunner->run($input->operationId, function () use ($input): void {
            $hold = $this->holds->capture($input->operationId, $input->holdId);

            $this->eventBus->dispatch(
                new HoldCapturedEvent(
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
