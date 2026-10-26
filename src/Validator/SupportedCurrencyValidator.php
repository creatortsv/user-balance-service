<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\CurrencyService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SupportedCurrencyValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CurrencyService $currencies,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SupportedCurrency) {
            throw new UnexpectedTypeException($constraint, SupportedCurrency::class);
        }

        if (!is_string($value) || !$this->currencies->supports($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ currencies }}', implode(', ', $this->currencies->codes()))
                ->setCode(SupportedCurrency::UNSUPPORTED_CURRENCY_ERROR)
                ->addViolation();
        }
    }
}
