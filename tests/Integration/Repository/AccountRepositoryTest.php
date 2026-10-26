<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\AccountRepository;
use App\ValueObject\Account;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(AccountRepository::class)]
final class AccountRepositoryTest extends KernelTestCase
{
    private AccountRepository $accounts;

    protected function setUp(): void
    {
        $this->accounts = self::getContainer()->get(AccountRepository::class);
    }

    protected function tearDown(): void
    {
        unset($this->accounts);

        parent::tearDown();
    }

    public function testRefusesToLockAccountOutsideTransaction(): void
    {
        $this->expectExceptionObject(new LogicException('An account can be locked only inside a transaction.'));

        $this->accounts->findForUpdate(Account::user(1), 'EUR');
    }
}
