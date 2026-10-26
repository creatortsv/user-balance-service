<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraints;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Amount extends Constraints\Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Constraints\Sequentially([
                new Constraints\Positive(),
                new CurrencyScale(),
            ]),
        ];
    }
}
