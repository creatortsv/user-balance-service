<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Event\FundsHeldEvent;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\Service\OperationRunner;
use App\UseCase\Dto\HoldFundsDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class HoldFunds
{
    public function __construct(
        private OperationRunner $operationRunner,
        private HoldService $holds,
        private LedgerService $ledger,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function execute(HoldFundsDto $input): void
    {
        $this->operationRunner->run($input->operationId, function () use ($input): void {
            $this->holds->place(
                $input->operationId,
                $input->userId,
                $input->currency,
                $input->amount,
            );

            $this->eventBus->dispatch(
                new FundsHeldEvent(
                    $input->operationId,
                    $input->amount,
                    $input->currency,
                    $this->ledger->balance($input->userId, $input->currency),
                    $this->clock->now(),
                ),
            );
        });
    }
}
