<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\RegenerateAllDerivativesJob;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 */
final class RegenerateAllDerivativesHandler
{
    public function __construct(
        private readonly DerivativeCacheService $derivativeCacheService,
    ) {}

    public function __invoke(RegenerateAllDerivativesJob $job): void
    {
        $this->derivativeCacheService->clearDerivativeCache($job->types);
    }
}
