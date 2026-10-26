<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\HoldRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(HoldRepository::class)]
final class HoldRepositoryTest extends KernelTestCase
{
    private HoldRepository $holds;

    protected function setUp(): void
    {
        $this->holds = self::getContainer()->get(HoldRepository::class);
    }

    protected function tearDown(): void
    {
        unset($this->holds);

        parent::tearDown();
    }

    public function testRefusesToLockHoldOutsideTransaction(): void
    {
        $this->expectExceptionObject(new LogicException('A hold can be locked only inside a transaction.'));

        $this->holds->findForUpdate('a0000000-0000-4000-8000-000000000001');
    }
}
