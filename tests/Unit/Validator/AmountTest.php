<?php

declare(strict_types=1);

namespace Tests\Unit\Validator;

use App\Message\CreditMessage;
use App\Service\CurrencyService;
use App\Validator\Amount;
use App\Validator\CurrencyScale;
use App\Validator\CurrencyScaleValidator;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(Amount::class)]
#[CoversClass(CurrencyScale::class)]
#[CoversClass(CurrencyScaleValidator::class)]
#[CoversClass(CurrencyService::class)]
final class AmountTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $currencies = new CurrencyService(
            [
                'EUR' => ['scale' => 2, 'limits' => []],
                'JPY' => ['scale' => 0, 'limits' => []],
            ],
        );
        $validators = new ConstraintValidatorFactory(
            [
                CurrencyScaleValidator::class => new CurrencyScaleValidator($currencies),
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

    #[DataProvider('validatesAmountDataProvider')]
    public function testValidatesAmount(string $currency, string $amount, array $expectedViolations): void
    {
        $message = new CreditMessage('a0000000-0000-4000-8000-000000000001', 1, $currency, new Number($amount));
        $actualViolations = [];

        foreach ($this->validator->validateProperty($message, 'amount') as $violation) {
            $actualViolations[] = $violation->getCode();
        }

        self::assertSame($expectedViolations, $actualViolations);
    }

    public static function validatesAmountDataProvider(): iterable
    {
        yield 'amount with cents' => [
            'currency' => 'EUR',
            'amount' => '10.10',
            'expectedViolations' => [],
        ];

        yield 'amount with one decimal' => [
            'currency' => 'EUR',
            'amount' => '10.5',
            'expectedViolations' => [],
        ];

        yield 'whole amount' => [
            'currency' => 'EUR',
            'amount' => '10',
            'expectedViolations' => [],
        ];

        yield 'one cent' => [
            'currency' => 'EUR',
            'amount' => '0.01',
            'expectedViolations' => [],
        ];

        yield 'large amount' => [
            'currency' => 'EUR',
            'amount' => '1000000000000000000000.00',
            'expectedViolations' => [],
        ];

        yield 'zero' => [
            'currency' => 'EUR',
            'amount' => '0.00',
            'expectedViolations' => [GreaterThan::TOO_LOW_ERROR],
        ];

        yield 'negative amount' => [
            'currency' => 'EUR',
            'amount' => '-10.00',
            'expectedViolations' => [GreaterThan::TOO_LOW_ERROR],
        ];

        yield 'fraction of a cent' => [
            'currency' => 'EUR',
            'amount' => '10.001',
            'expectedViolations' => [CurrencyScale::TOO_MANY_DECIMALS_ERROR],
        ];

        yield 'whole yen' => [
            'currency' => 'JPY',
            'amount' => '10',
            'expectedViolations' => [],
        ];

        yield 'fraction of a yen' => [
            'currency' => 'JPY',
            'amount' => '10.5',
            'expectedViolations' => [CurrencyScale::TOO_MANY_DECIMALS_ERROR],
        ];

        yield 'unsupported currency' => [
            'currency' => 'USD',
            'amount' => '10.001',
            'expectedViolations' => [],
        ];
    }
}
