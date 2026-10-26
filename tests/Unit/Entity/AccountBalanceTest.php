<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use App\Entity\AccountBalance;
use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use App\ValueObject\Account;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccountBalance::class)]
#[CoversClass(OperationRejectedException::class)]
final class AccountBalanceTest extends TestCase
{
    #[DataProvider('postsAmountDataProvider')]
    public function testPostsAmount(string $amount, string $expectedBalance): void
    {
        $account = new AccountBalance(Account::user(1), new Number('10.00'), 'EUR', 3);

        $posted = $account->post(new Number($amount));

        self::assertSame(
            ['balance' => $expectedBalance, 'version' => 4],
            ['balance' => $posted->balance->value, 'version' => $posted->version],
        );
    }

    public static function postsAmountDataProvider(): iterable
    {
        yield 'credit' => ['amount' => '2.50', 'expectedBalance' => '12.50'];

        yield 'partial debit' => ['amount' => '-2.50', 'expectedBalance' => '7.50'];

        yield 'full debit' => ['amount' => '-10.00', 'expectedBalance' => '0.00'];
    }

    public function testRejectsDebitAboveBalance(): void
    {
        $account = new AccountBalance(Account::user(1), new Number('10.00'), 'EUR', 3);

        $this->expectExceptionObject(new OperationRejectedException(RejectionReason::InsufficientFunds));

        $account->post(new Number('-10.01'));
    }
}
