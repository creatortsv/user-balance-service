<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TransactionType;
use BcMath\Number;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CurrencyService
{
    /**
     * @param array<string, array{scale: int, limits: array<string, numeric-string>}> $currencies
     */
    public function __construct(
        #[Autowire('%balance.currencies%')]
        private array $currencies,
    ) {
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->currencies);
    }

    public function supports(string $currency): bool
    {
        return array_key_exists($currency, $this->currencies);
    }

    public function scale(string $currency): ?int
    {
        return $this->currencies[$currency]['scale'] ?? null;
    }

    // TODO: валюты и лимиты меняются только деплоем, лучше в админку и добавить лимиты на день и на пользователя
    public function limit(string $currency, TransactionType $type): ?Number
    {
        $limit = $this->currencies[$currency]['limits'][$type->value] ?? null;

        return $limit === null ? null : new Number($limit);
    }
}
