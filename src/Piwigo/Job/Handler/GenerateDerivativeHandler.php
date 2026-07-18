<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Image\DerivativeCacheService;
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
        $infos = [
            'path' => $job->path,
        ];
        if ($job->representativeExt !== null) {
            $infos['representative_ext'] = $job->representativeExt;
        }

        $this->derivativeCacheService->deleteElementDerivatives($infos, $job->type);
    }
}
