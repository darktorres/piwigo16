<?php

declare(strict_types=1);

namespace Piwigo\Job;

final readonly class RegenerateAllDerivativesJob
{
    /** @param string[] $types Derivative type names to regenerate, e.g. ['thumb', 'medium'] */
    public function __construct(
        public array $types,
    ) {
    }
}
