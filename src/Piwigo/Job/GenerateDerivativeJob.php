<?php

declare(strict_types=1);

namespace Piwigo\Job;

/**
 * Piwigo's derivative generation is lazy (i.php regenerates a missing/
 * stale derivative on the next request that needs it, SEC-33/35, P19) --
 * "generate this element's derivative" is therefore really "invalidate
 * its cached file(s), so the next view regenerates them". Mirrors
 * Piwigo\Image\DerivativeCacheService::deleteElementDerivatives()'s own
 * parameter shape; GenerateDerivativeHandler is a thin delegate to that
 * real, already-built service.
 */
final readonly class GenerateDerivativeJob
{
    public function __construct(
        public string $path,
        public ?string $representativeExt = null,
        public string $type = 'all',
    ) {}
}
