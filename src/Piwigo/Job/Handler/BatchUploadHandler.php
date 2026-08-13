<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Job\BatchUploadJob;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 *
 * Delegates to UploadService::addUploadedFile(), resolved via
 * Bootstrap\AdminAccessor::uploadService() rather than constructor-
 * injected like this class's siblings (GenerateDerivativeHandler/
 * RegenerateAllDerivativesHandler/ReindexImagesHandler): UploadService is
 * `final` (no subclass test double) with no interface (no mock seam), and
 * its real execution ends with a genuine self-fetchRemote() HTTP call
 * back into the running app to force derivative-cache generation -- only
 * a live Browser-tier context can safely exercise it. Extracting a
 * proper, independently testable service abstraction here would require
 * a larger refactor (tus upload support) and is out of scope for this
 * job handler.
 */
final readonly class BatchUploadHandler
{
    public function __construct(
        private UrlServiceInterface $urlService,
    ) {}

    public function __invoke(BatchUploadJob $job): int
    {
        return AdminAccessor::uploadService()
            ->addUploadedFile(
                $job->sourceFilepath,
                $this->urlService,
                $job->originalFilename,
                $job->categories,
                $job->level,
                $job->imageId?->value,
                $job->originalMd5sum,
            );
    }
}
