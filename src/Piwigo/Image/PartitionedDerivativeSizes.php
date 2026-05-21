<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** Enabled and disabled derivative sizes loaded from the database. */
final readonly class PartitionedDerivativeSizes
{
    /**
     * @param array<string, DerivativeParams> $enabled
     * @param array<string, DerivativeParams> $disabled
     */
    public function __construct(
        public array $enabled,
        public array $disabled,
    ) {
    }
}
