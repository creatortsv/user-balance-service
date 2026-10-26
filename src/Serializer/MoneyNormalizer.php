<?php

declare(strict_types=1);

namespace App\Serializer;

use BcMath\Number;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class MoneyNormalizer implements NormalizerInterface
{
    public const string SCALE = 'currency_scale';

    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        if (!$data instanceof Number) {
            throw new InvalidArgumentException(sprintf('The data must be a "%s".', Number::class));
        }

        $scale = $context[self::SCALE] ?? null;

        return is_int($scale) ? $data->round($scale)->value : $data->value;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Number;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Number::class => true];
    }
}
