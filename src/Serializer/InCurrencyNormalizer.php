<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Service\CurrencyService;
use ArrayObject;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class InCurrencyNormalizer implements NormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private NormalizerInterface $objects,
        private CurrencyService $currencies,
    ) {
    }

    public function normalize(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): array|string|int|float|bool|ArrayObject|null {
        if (!$data instanceof InCurrency) {
            throw new InvalidArgumentException(sprintf('The data must be a "%s".', InCurrency::class));
        }

        return $this->objects->normalize(
            $data,
            $format,
            [
                ...$context,
                MoneyNormalizer::SCALE => $this->currencies->scale($data->currency),
            ],
        );
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof InCurrency;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [InCurrency::class => true];
    }
}
