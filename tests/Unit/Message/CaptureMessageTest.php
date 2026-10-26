<?php

declare(strict_types=1);

namespace Tests\Unit\Message;

use App\Message\CaptureMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CaptureMessage::class)]
final class CaptureMessageTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    protected function tearDown(): void
    {
        unset($this->validator);
    }

    #[DataProvider('validatesMessageDataProvider')]
    public function testValidatesMessage(string $operationId, string $holdId, array $expectedViolations): void
    {
        $message = new CaptureMessage($operationId, $holdId);
        $actualViolations = [];

        foreach ($this->validator->validate($message) as $violation) {
            $actualViolations[] = [$violation->getPropertyPath(), $violation->getCode()];
        }

        self::assertSame($expectedViolations, $actualViolations);
    }

    public static function validatesMessageDataProvider(): iterable
    {
        yield 'valid message' => [
            'operationId' => 'a0000000-0000-4000-8000-000000000001',
            'holdId' => 'b0000000-0000-4000-8000-000000000002',
            'expectedViolations' => [],
        ];

        yield 'blank ids' => [
            'operationId' => '',
            'holdId' => '',
            'expectedViolations' => [
                ['operationId', NotBlank::IS_BLANK_ERROR],
                ['holdId', NotBlank::IS_BLANK_ERROR],
            ],
        ];

        yield 'invalid ids' => [
            'operationId' => 'some',
            'holdId' => 'some',
            'expectedViolations' => [
                ['operationId', Uuid::INVALID_CHARACTERS_ERROR],
                ['holdId', Uuid::INVALID_CHARACTERS_ERROR],
            ],
        ];
    }
}
