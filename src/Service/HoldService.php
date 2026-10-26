<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Hold;
use App\Entity\LedgerTransaction;
use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use App\Repository\HoldRepository;
use BcMath\Number;

final readonly class HoldService
{
    public function __construct(
        private LedgerService $ledger,
        private HoldRepository $holds,
    ) {
    }

    public function place(string $id, int $userId, string $currency, Number $amount): void
    {
        $this->ledger->post(
            LedgerTransaction::hold(
                $id,
                $userId,
                $currency,
                $amount,
            ),
        );

        $this->holds->add(
            new Hold(
                $id,
                $userId,
                $currency,
                $amount,
            ),
        );
    }

    // TODO: холд держит деньги, пока его не подтвердят или не отменят, нет срока жизни и частичного списания
    public function capture(string $operationId, string $holdId): Hold
    {
        $hold = $this->findForUpdate($holdId)->capture();

        $this->holds->save($hold);
        $this->ledger->post(
            LedgerTransaction::capture(
                $operationId,
                $hold,
            ),
        );

        return $hold;
    }

    public function release(string $operationId, string $holdId): Hold
    {
        $hold = $this->findForUpdate($holdId)->release();

        $this->holds->save($hold);
        $this->ledger->post(
            LedgerTransaction::release(
                $operationId,
                $hold,
            ),
        );

        return $hold;
    }

    private function findForUpdate(string $holdId): Hold
    {
        return $this->holds->findForUpdate($holdId)
            ?? throw new OperationRejectedException(RejectionReason::HoldNotFound);
    }
}
