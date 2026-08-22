<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\Projection\DerivativePathInfo;
use Piwigo\Job\GenerateDerivativeJob;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 */
final readonly class GenerateDerivativeHandler
{
    public function __construct(
        private DerivativeCacheService $derivativeCacheService,
    ) {}

    public function __invoke(GenerateDerivativeJob $job): void
    {
        $this->derivativeCacheService->deleteElementDerivatives(
            new DerivativePathInfo($job->path, $job->representativeExt),
            $job->type
        );
    }
}
