<?php

declare(strict_types=1);

namespace App\Validator;

use App\Serializer\InCurrency;
use App\Service\CurrencyService;
use BcMath\Number;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CurrencyScaleValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CurrencyService $currencies,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CurrencyScale) {
            throw new UnexpectedTypeException($constraint, CurrencyScale::class);
        }

        if (!$value instanceof Number) {
            throw new UnexpectedValueException($value, Number::class);
        }

        $object = $this->context->getObject();

        if (!$object instanceof InCurrency) {
            throw new ConstraintDefinitionException(
                sprintf('The amount must belong to a "%s".', InCurrency::class),
            );
        }

        $scale = $this->currencies->scale($object->currency);

        if ($scale !== null && $value->scale > $scale) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ scale }}', (string) $scale)
                ->setCode(CurrencyScale::TOO_MANY_DECIMALS_ERROR)
                ->addViolation();
        }
    }
}
