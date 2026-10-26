<?php

declare(strict_types=1);

namespace App\Repository;

use BcMath\Number;
use UnexpectedValueException;

final readonly class Row
{
    /**
     * @var array<string, mixed>
     */
    private array $values;

    /**
     * @param array<string, mixed>|false $values
     */
    public function __construct(array|false $values)
    {
        if ($values === false) {
            throw new UnexpectedValueException('The query returned no row.');
        }

        $this->values = $values;
    }

    public function bool(string $column): bool
    {
        $value = $this->value($column);

        if (!is_bool($value)) {
            throw $this->unexpected($column, 'a boolean');
        }

        return $value;
    }

    public function int(string $column): int
    {
        $value = $this->value($column);

        if (!is_int($value)) {
            throw $this->unexpected($column, 'an integer');
        }

        return $value;
    }

    public function string(string $column): string
    {
        $value = $this->value($column);

        if (!is_string($value)) {
            throw $this->unexpected($column, 'a string');
        }

        return $value;
    }

    public function number(string $column): Number
    {
        $value = $this->value($column);

        if (!is_string($value) || !is_numeric($value)) {
            throw $this->unexpected($column, 'a number');
        }

        return new Number($value);
    }

    private function value(string $column): mixed
    {
        if (!array_key_exists($column, $this->values)) {
            throw new UnexpectedValueException(sprintf('The row has no "%s" column.', $column));
        }

        return $this->values[$column];
    }

    private function unexpected(string $column, string $type): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('The "%s" column is not %s.', $column, $type));
    }
}
