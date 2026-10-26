<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Entity\LedgerTransaction;
use App\Event\BalanceDebitedEvent;
use App\Service\LedgerService;
use App\Service\OperationRunner;
use App\UseCase\Dto\DebitBalanceDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DebitBalance
{
    public function __construct(
        private OperationRunner $operationRunner,
        private LedgerService $ledger,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function execute(DebitBalanceDto $input): void
    {
        $this->operationRunner->run($input->operationId, function () use ($input): void {
            $this->ledger->post(
                LedgerTransaction::debit(
                    $input->operationId,
                    $input->userId,
                    $input->currency,
                    $input->amount,
                ),
            );

            $this->eventBus->dispatch(
                new BalanceDebitedEvent(
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
