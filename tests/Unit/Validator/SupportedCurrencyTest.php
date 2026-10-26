<?php

declare(strict_types=1);

namespace Tests\Unit\Validator;

use App\Service\CurrencyService;
use App\Validator\SupportedCurrency;
use App\Validator\SupportedCurrencyValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(SupportedCurrency::class)]
#[CoversClass(SupportedCurrencyValidator::class)]
#[CoversClass(CurrencyService::class)]
final class SupportedCurrencyTest extends TestCase
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
                SupportedCurrencyValidator::class => new SupportedCurrencyValidator($currencies),
            ],
        );
        $this->validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory($validators)
            ->getValidator();
    }

    protected function tearDown(): void
    {
        unset($this->validator);
    }

    #[DataProvider('validatesCurrencyDataProvider')]
    public function testValidatesCurrency(string $currency, array $expectedViolations): void
    {
        $actualViolations = [];

        foreach ($this->validator->validate($currency, new SupportedCurrency()) as $violation) {
            $actualViolations[] = [$violation->getCode(), $violation->getParameters()];
        }

        self::assertSame($expectedViolations, $actualViolations);
    }

    public static function validatesCurrencyDataProvider(): iterable
    {
        yield 'supported currency' => [
            'currency' => 'EUR',
            'expectedViolations' => [],
        ];

        yield 'another supported currency' => [
            'currency' => 'JPY',
            'expectedViolations' => [],
        ];

        yield 'unsupported currency' => [
            'currency' => 'USD',
            'expectedViolations' => [
                [SupportedCurrency::UNSUPPORTED_CURRENCY_ERROR, ['{{ currencies }}' => 'EUR, JPY']],
            ],
        ];

        yield 'lower case' => [
            'currency' => 'eur',
            'expectedViolations' => [
                [SupportedCurrency::UNSUPPORTED_CURRENCY_ERROR, ['{{ currencies }}' => 'EUR, JPY']],
            ],
        ];
    }
}
