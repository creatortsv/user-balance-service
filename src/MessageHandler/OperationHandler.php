<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CaptureMessage;
use App\Message\CreditMessage;
use App\Message\DebitMessage;
use App\Message\HoldMessage;
use App\Message\ReleaseMessage;
use App\Message\TransferMessage;
use App\UseCase\CaptureHold;
use App\UseCase\CreditBalance;
use App\UseCase\DebitBalance;
use App\UseCase\Dto\CaptureHoldDto;
use App\UseCase\Dto\CreditBalanceDto;
use App\UseCase\Dto\DebitBalanceDto;
use App\UseCase\Dto\HoldFundsDto;
use App\UseCase\Dto\ReleaseHoldDto;
use App\UseCase\Dto\TransferFundsDto;
use App\UseCase\HoldFunds;
use App\UseCase\ReleaseHold;
use App\UseCase\TransferFunds;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// TODO: все команды в одном классе, потому что каждая только перекладывает поля в DTO, с логикой лучше в свой класс
final readonly class OperationHandler
{
    public function __construct(
        private CreditBalance $creditBalance,
        private DebitBalance $debitBalance,
        private HoldFunds $holdFunds,
        private CaptureHold $captureHold,
        private ReleaseHold $releaseHold,
        private TransferFunds $transferFunds,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function credit(CreditMessage $message): void
    {
        $this->creditBalance->execute(
            new CreditBalanceDto(
                $message->operationId,
                $message->userId,
                $message->currency,
                $message->amount,
            ),
        );
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function debit(DebitMessage $message): void
    {
        $this->debitBalance->execute(
            new DebitBalanceDto(
                $message->operationId,
                $message->userId,
                $message->currency,
                $message->amount,
            ),
        );
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function hold(HoldMessage $message): void
    {
        $this->holdFunds->execute(
            new HoldFundsDto(
                $message->operationId,
                $message->userId,
                $message->currency,
                $message->amount,
            ),
        );
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function capture(CaptureMessage $message): void
    {
        $this->captureHold->execute(
            new CaptureHoldDto(
                $message->operationId,
                $message->holdId,
            ),
        );
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function release(ReleaseMessage $message): void
    {
        $this->releaseHold->execute(
            new ReleaseHoldDto(
                $message->operationId,
                $message->holdId,
            ),
        );
    }

    #[AsMessageHandler(bus: 'command.bus', fromTransport: 'inbox')]
    public function transfer(TransferMessage $message): void
    {
        $this->transferFunds->execute(
            new TransferFundsDto(
                $message->operationId,
                $message->fromUserId,
                $message->toUserId,
                $message->currency,
                $message->amount,
            ),
        );
    }
}
