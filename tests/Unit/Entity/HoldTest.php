<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use App\Entity\Hold;
use App\Enum\HoldStatus;
use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hold::class)]
#[CoversClass(OperationRejectedException::class)]
final class HoldTest extends TestCase
{
    private const string HOLD_ID = 'a0000000-0000-4000-8000-000000000001';

    public function testCapturesActiveHold(): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('2.50'), HoldStatus::Active);

        self::assertSame(HoldStatus::Captured, $hold->capture()->status);
    }

    public function testReleasesActiveHold(): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('2.50'), HoldStatus::Active);

        self::assertSame(HoldStatus::Released, $hold->release()->status);
    }

    #[DataProvider('rejectsCaptureOfFinishedHoldDataProvider')]
    public function testRejectsCaptureOfFinishedHold(HoldStatus $status): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('2.50'), $status);

        $this->expectExceptionObject(new OperationRejectedException(RejectionReason::HoldNotActive));

        $hold->capture();
    }

    public static function rejectsCaptureOfFinishedHoldDataProvider(): iterable
    {
        yield 'captured hold' => ['status' => HoldStatus::Captured];

        yield 'released hold' => ['status' => HoldStatus::Released];
    }

    #[DataProvider('rejectsReleaseOfFinishedHoldDataProvider')]
    public function testRejectsReleaseOfFinishedHold(HoldStatus $status): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('2.50'), $status);

        $this->expectExceptionObject(new OperationRejectedException(RejectionReason::HoldNotActive));

        $hold->release();
    }

    public static function rejectsReleaseOfFinishedHoldDataProvider(): iterable
    {
        yield 'captured hold' => ['status' => HoldStatus::Captured];

        yield 'released hold' => ['status' => HoldStatus::Released];
    }
}
