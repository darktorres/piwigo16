<?php

declare(strict_types=1);

namespace Piwigo\Job;

/**
 * Same lazy-regeneration model as GenerateDerivativeJob, at the scale of
 * one or more whole derivative types. Mirrors Piwigo\Image\
 * DerivativeCacheService::clearDerivativeCache()'s own parameter shape;
 * RegenerateAllDerivativesHandler is a thin delegate to that real,
 * already-built service.
 */
final readonly class RegenerateAllDerivativesJob
{
    /**
     * @param 'all'|string|array<int|string, string> $types
     */
    public function __construct(
        public string|array $types = 'all',
    ) {}
}
