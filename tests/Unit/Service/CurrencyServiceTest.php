<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enum\TransactionType;
use App\Service\CurrencyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurrencyService::class)]
final class CurrencyServiceTest extends TestCase
{
    #[DataProvider('findsScaleOfCurrencyDataProvider')]
    public function testFindsScaleOfCurrency(string $currency, ?int $expectedScale): void
    {
        $currencies = new CurrencyService(
            [
                'EUR' => ['scale' => 2, 'limits' => []],
                'JPY' => ['scale' => 0, 'limits' => []],
            ],
        );

        self::assertSame($expectedScale, $currencies->scale($currency));
    }

    public static function findsScaleOfCurrencyDataProvider(): iterable
    {
        yield 'currency with cents' => ['currency' => 'EUR', 'expectedScale' => 2];

        yield 'currency without decimals' => ['currency' => 'JPY', 'expectedScale' => 0];

        yield 'unsupported currency' => ['currency' => 'USD', 'expectedScale' => null];
    }

    #[DataProvider('findsLimitOfOperationDataProvider')]
    public function testFindsLimitOfOperation(TransactionType $type, string $expectedLimit): void
    {
        $currencies = new CurrencyService(
            [
                'EUR' => ['scale' => 2, 'limits' => ['credit' => '100000.00', 'debit' => '10000.00']],
            ],
        );

        self::assertSame($expectedLimit, $currencies->limit('EUR', $type)->value);
    }

    public static function findsLimitOfOperationDataProvider(): iterable
    {
        yield 'credit' => ['type' => TransactionType::Credit, 'expectedLimit' => '100000.00'];

        yield 'debit' => ['type' => TransactionType::Debit, 'expectedLimit' => '10000.00'];
    }

    #[DataProvider('findsNoLimitDataProvider')]
    public function testFindsNoLimit(string $currency, TransactionType $type): void
    {
        $currencies = new CurrencyService(
            [
                'EUR' => ['scale' => 2, 'limits' => ['credit' => '100000.00']],
                'JPY' => ['scale' => 0, 'limits' => []],
            ],
        );

        self::assertSame(null, $currencies->limit($currency, $type));
    }

    public static function findsNoLimitDataProvider(): iterable
    {
        yield 'operation without a limit' => ['currency' => 'EUR', 'type' => TransactionType::Transfer];

        yield 'currency without limits' => ['currency' => 'JPY', 'type' => TransactionType::Credit];

        yield 'unsupported currency' => ['currency' => 'USD', 'type' => TransactionType::Credit];
    }
}
