<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Message\CaptureMessage;
use App\Middleware\RetryPolicyMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\ValidationMiddleware;
use Symfony\Component\Validator\Validation;

#[CoversClass(RetryPolicyMiddleware::class)]
final class RetryPolicyMiddlewareTest extends TestCase
{
    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string HOLD_ID = 'b0000000-0000-4000-8000-000000000002';

    public function testRefusesToRetryInvalidCommand(): void
    {
        $connection = $this->createStub(Connection::class);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $bus = new MessageBus([new RetryPolicyMiddleware($connection), new ValidationMiddleware($validator)]);

        $this->expectExceptionObject(
            new UnrecoverableMessageHandlingException(
                'operationId: This is not a valid UUID. holdId: This value should not be blank.',
            ),
        );

        $bus->dispatch(new CaptureMessage('some', ''));
    }

    public function testRetriesConnectionErrorInHandler(): void
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('isConnected')->willReturn(true);

        $exception = new ConnectionException(DriverException::new(new PDOException('Some error.')), null);
        $handlers = new HandlersLocator([CaptureMessage::class => [static fn () => throw $exception]]);
        $bus = new MessageBus([new RetryPolicyMiddleware($connection), new HandleMessageMiddleware($handlers)]);

        $this->expectExceptionObject(
            new RecoverableMessageHandlingException(
                'Handling "App\Message\CaptureMessage" failed: An exception occurred in the driver: Some error.',
            ),
        );

        $bus->dispatch(new CaptureMessage(self::OPERATION_ID, self::HOLD_ID));
    }

    public function testRetriesWhenConnectionIsLost(): void
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('isConnected')->willReturn(false);

        $exception = NoActiveTransaction::new();
        $handlers = new HandlersLocator([CaptureMessage::class => [static fn () => throw $exception]]);
        $bus = new MessageBus([new RetryPolicyMiddleware($connection), new HandleMessageMiddleware($handlers)]);

        $this->expectExceptionObject(
            new RecoverableMessageHandlingException(
                'Handling "App\Message\CaptureMessage" failed: There is no active transaction.',
            ),
        );

        $bus->dispatch(new CaptureMessage(self::OPERATION_ID, self::HOLD_ID));
    }

    public function testRetriesConnectionErrorBeforeHandler(): void
    {
        $connection = $this->createStub(Connection::class);
        $nextMiddleware = $this->createStub(MiddlewareInterface::class);

        $connection->method('isConnected')->willReturn(true);

        $nextMiddleware->method('handle')->willThrowException(
            new ConnectionException(DriverException::new(new PDOException('Some error.')), null),
        );

        $bus = new MessageBus([new RetryPolicyMiddleware($connection), $nextMiddleware]);

        $this->expectExceptionObject(
            new RecoverableMessageHandlingException('An exception occurred in the driver: Some error.'),
        );

        $bus->dispatch(new CaptureMessage(self::OPERATION_ID, self::HOLD_ID));
    }

    public function testLeavesOtherFailuresToRetryStrategy(): void
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('isConnected')->willReturn(true);

        $command = new CaptureMessage(self::OPERATION_ID, self::HOLD_ID);
        $exception = new RuntimeException('Some error.');
        $handlers = new HandlersLocator([CaptureMessage::class => [static fn () => throw $exception]]);
        $bus = new MessageBus([new RetryPolicyMiddleware($connection), new HandleMessageMiddleware($handlers)]);

        $this->expectExceptionObject(new HandlerFailedException(new Envelope($command), [$exception]));

        $bus->dispatch($command);
    }
}
