<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataService;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 */
final class ReindexImagesHandler
{
    public function __construct(
        private readonly MetadataService $metadataService,
    ) {}

    public function __invoke(ReindexImagesJob $job): void
    {
        $this->metadataService->syncMetadata($job->imageIds);
    }
}
