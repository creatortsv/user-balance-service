<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class Account
{
    private const string WORLD = 'world';

    private function __construct(
        public string $name,
    ) {
    }

    public static function world(): self
    {
        return new self(self::WORLD);
    }

    public static function user(int $userId): self
    {
        return new self(sprintf('user:%d', $userId));
    }

    public static function hold(int $userId): self
    {
        return new self(sprintf('user:%d:hold', $userId));
    }

    public function hasStoredBalance(): bool
    {
        return $this->name !== self::WORLD;
    }
}
