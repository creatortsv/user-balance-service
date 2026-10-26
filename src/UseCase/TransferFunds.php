<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Entity\LedgerTransaction;
use App\Event\FundsTransferredEvent;
use App\Service\LedgerService;
use App\Service\OperationRunner;
use App\UseCase\Dto\TransferFundsDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class TransferFunds
{
    public function __construct(
        private OperationRunner $operationRunner,
        private LedgerService $ledger,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function execute(TransferFundsDto $input): void
    {
        $this->operationRunner->run($input->operationId, function () use ($input): void {
            $this->ledger->post(
                LedgerTransaction::transfer(
                    $input->operationId,
                    $input->fromUserId,
                    $input->toUserId,
                    $input->currency,
                    $input->amount,
                ),
            );

            $this->eventBus->dispatch(
                new FundsTransferredEvent(
                    $input->operationId,
                    $input->amount,
                    $input->currency,
                    $this->ledger->balance($input->fromUserId, $input->currency),
                    $this->ledger->balance($input->toUserId, $input->currency),
                    $this->clock->now(),
                ),
            );
        });
    }
}
