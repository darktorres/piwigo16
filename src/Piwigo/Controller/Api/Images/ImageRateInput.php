<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `PUT /api/v1/images/{id}/rating` body DTO -- mirrors
 * `Ws\Images\RateParams`'s own `rate` field.
 */
final readonly class ImageRateInput
{
    public function __construct(
        public int|string|null $rate,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $rate = $raw['rate'] ?? null;

        return new self(
            rate: (is_int($rate) || is_string($rate)) ? $rate : null,
        );
    }
}
