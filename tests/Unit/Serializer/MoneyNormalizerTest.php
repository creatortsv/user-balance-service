<?php

declare(strict_types=1);

namespace Tests\Unit\Serializer;

use App\Serializer\MoneyNormalizer;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoneyNormalizer::class)]
final class MoneyNormalizerTest extends TestCase
{
    private MoneyNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MoneyNormalizer();
    }

    protected function tearDown(): void
    {
        unset($this->normalizer);
    }

    #[DataProvider('writesAmountWithScaleOfCurrencyDataProvider')]
    public function testWritesAmountWithScaleOfCurrency(string $amount, ?int $scale, string $expectedAmount): void
    {
        self::assertSame(
            $expectedAmount,
            $this->normalizer->normalize(new Number($amount), null, [MoneyNormalizer::SCALE => $scale]),
        );
    }

    public static function writesAmountWithScaleOfCurrencyDataProvider(): iterable
    {
        yield 'whole amount' => ['amount' => '10', 'scale' => 2, 'expectedAmount' => '10.00'];

        yield 'amount with one decimal' => ['amount' => '10.5', 'scale' => 2, 'expectedAmount' => '10.50'];

        yield 'amount with cents' => ['amount' => '10.55', 'scale' => 2, 'expectedAmount' => '10.55'];

        yield 'currency without decimals' => ['amount' => '10', 'scale' => 0, 'expectedAmount' => '10'];

        yield 'unknown currency' => ['amount' => '10.5', 'scale' => null, 'expectedAmount' => '10.5'];
    }
}
