<?php

declare(strict_types=1);

namespace App\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class RetryPolicyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (ValidationFailedException $exception) {
            // TODO: на невалидную команду отправитель не получает ответа, ей тоже стоит слать balance.rejected
            throw new UnrecoverableMessageHandlingException($this->describe($exception), previous: $exception);
        } catch (HandlerFailedException | DatabaseException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() : $exception;

            if ($cause instanceof ConnectionException || !$this->connection->isConnected()) {
                throw new RecoverableMessageHandlingException($exception->getMessage(), previous: $exception);
            }

            throw $exception;
        }
    }

    private function describe(ValidationFailedException $exception): string
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $violations[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        return implode(' ', $violations);
    }
}
