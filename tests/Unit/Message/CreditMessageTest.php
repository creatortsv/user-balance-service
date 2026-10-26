<?php

declare(strict_types=1);

namespace Tests\Unit\Message;

use App\Message\CreditMessage;
use App\Service\CurrencyService;
use App\Validator\CurrencyScaleValidator;
use App\Validator\SupportedCurrency;
use App\Validator\SupportedCurrencyValidator;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreditMessage::class)]
final class CreditMessageTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $currencies = new CurrencyService(['EUR' => ['scale' => 2, 'limits' => []]]);
        $validators = new ConstraintValidatorFactory(
            [
                CurrencyScaleValidator::class => new CurrencyScaleValidator($currencies),
                SupportedCurrencyValidator::class => new SupportedCurrencyValidator($currencies),
            ],
        );
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory($validators)
            ->getValidator();
    }

    protected function tearDown(): void
    {
        unset($this->validator);
    }

    #[DataProvider('validatesMessageDataProvider')]
    public function testValidatesMessage(
        string $operationId,
        int $userId,
        string $currency,
        string $amount,
        array $expectedViolations,
    ): void {
        $message = new CreditMessage($operationId, $userId, $currency, new Number($amount));
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
            'userId' => 1,
            'currency' => 'EUR',
            'amount' => '10.00',
            'expectedViolations' => [],
        ];

        yield 'blank operation id' => [
            'operationId' => '',
            'userId' => 1,
            'currency' => 'EUR',
            'amount' => '10.00',
            'expectedViolations' => [
                ['operationId', NotBlank::IS_BLANK_ERROR],
            ],
        ];

        yield 'invalid fields' => [
            'operationId' => 'some',
            'userId' => 0,
            'currency' => 'USD',
            'amount' => '0.00',
            'expectedViolations' => [
                ['operationId', Uuid::INVALID_CHARACTERS_ERROR],
                ['userId', GreaterThan::TOO_LOW_ERROR],
                ['currency', SupportedCurrency::UNSUPPORTED_CURRENCY_ERROR],
                ['amount', GreaterThan::TOO_LOW_ERROR],
            ],
        ];
    }
}
