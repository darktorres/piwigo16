<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\DerivativeCacheService::deleteElementDerivatives()}'s
 * own parameter shape -- genuinely just `path`/`representative_ext`, no
 * element id at all ({@see \Piwigo\Job\GenerateDerivativeJob}, one of its
 * 4 real callers, confirms this: it has no id property to carry one).
 */
final readonly class DerivativePathInfo
{
    public function __construct(
        public string $path,
        public ?string $representativeExt = null,
    ) {}
}
