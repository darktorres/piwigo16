<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** Derivative-wide settings: JPEG quality, watermark config, and custom-size recency map. */
final readonly class DerivativeSettings
{
    /** @param array<string, int> $custom */
    public function __construct(
        public int $quality,
        public WatermarkParams $watermark,
        public array $custom,
    ) {
    }
}
