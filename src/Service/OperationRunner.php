<?php

declare(strict_types=1);

namespace App\Service;

use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use Closure;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class OperationRunner
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param Closure(): void $operation
     */
    public function run(string $operationId, Closure $operation): void
    {
        // TODO: нет lock_timeout, поэтому если чужая транзакция зависнет на строке, операция будет ждать ее без конца
        try {
            $this->connection->transactional($operation);
        } catch (OperationRejectedException $exception) {
            $this->eventBus->dispatch(
                new OperationRejectedEvent(
                    $operationId,
                    $exception->reason,
                    $this->clock->now(),
                ),
            );
        }
    }
}
