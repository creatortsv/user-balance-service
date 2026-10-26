<?php

declare(strict_types=1);

namespace Tests\Unit\Serializer;

use App\Event\BalanceCreditedEvent;
use App\Serializer\InCurrencyNormalizer;
use App\Serializer\MoneyNormalizer;
use App\Service\CurrencyService;
use App\ValueObject\Balance;
use BcMath\Number;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[CoversClass(InCurrencyNormalizer::class)]
#[CoversClass(CurrencyService::class)]
final class InCurrencyNormalizerTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $objectNormalizer = new ObjectNormalizer();
        $currencies = new CurrencyService(
            [
                'EUR' => ['scale' => 2, 'limits' => []],
                'JPY' => ['scale' => 0, 'limits' => []],
            ],
        );
        $this->serializer = new Serializer(
            [
                new InCurrencyNormalizer($objectNormalizer, $currencies),
                new MoneyNormalizer(),
                new DateTimeNormalizer(),
                $objectNormalizer,
            ],
        );
    }

    protected function tearDown(): void
    {
        unset($this->serializer);
    }

    #[DataProvider('writesAmountsWithScaleOfCurrencyDataProvider')]
    public function testWritesAmountsWithScaleOfCurrency(string $currency, array $expectedAmounts): void
    {
        $event = new BalanceCreditedEvent(
            'a0000000-0000-4000-8000-000000000001',
            new Number('10'),
            $currency,
            new Balance(1, new Number('10'), 1, new Number('0'), 0),
            new DateTimeImmutable('2026-10-24 12:00:00'),
        );

        $body = $this->serializer->normalize($event);

        self::assertSame(
            $expectedAmounts,
            [
                'amount' => $body['amount'],
                'available' => $body['balance']['available'],
                'held' => $body['balance']['held'],
            ],
        );
    }

    public static function writesAmountsWithScaleOfCurrencyDataProvider(): iterable
    {
        yield 'currency with cents' => [
            'currency' => 'EUR',
            'expectedAmounts' => ['amount' => '10.00', 'available' => '10.00', 'held' => '0.00'],
        ];

        yield 'currency without decimals' => [
            'currency' => 'JPY',
            'expectedAmounts' => ['amount' => '10', 'available' => '10', 'held' => '0'],
        ];

        yield 'unsupported currency' => [
            'currency' => 'USD',
            'expectedAmounts' => ['amount' => '10', 'available' => '10', 'held' => '0'],
        ];
    }
}
