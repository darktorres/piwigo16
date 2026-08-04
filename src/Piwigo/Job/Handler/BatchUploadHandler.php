<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Storage\StorageRegistry;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 *
 * Delegates to UploadService::addUploadedFile() (formerly the free
 * function add_uploaded_file() in admin/include/functions_upload.inc.php,
 * deleted in P23 sub-batch 8b-3) -- constructed inline rather than
 * constructor-injected like this class's siblings
 * (GenerateDerivativeHandler/RegenerateAllDerivativesHandler/
 * ReindexImagesHandler), since UploadService is `final` (no subclass test
 * double) with no interface (no mock seam) and its real execution ends
 * with a genuine self-fetchRemote() HTTP call back into the running app
 * to force derivative-cache generation -- only a live Browser-tier
 * context can safely exercise it. Extracting a proper, independently
 * testable service abstraction here is still a P21+-scale refactor (tus
 * upload support), out of this greenfield job-mechanism phase's scope;
 * this fold only needed to keep the call working once the free-function
 * bridge disappeared.
 */
final class BatchUploadHandler
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly StorageRegistry $storageRegistry,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\ConfigService $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly \Piwigo\Activity\ActivityService $activityService,
        private readonly \Piwigo\Metadata\MetadataService $metadataService,
    ) {}

    public function __invoke(BatchUploadJob $job): int
    {
        return new UploadService($this->currentLogger, $this->storageRegistry, $this->eventDispatcher, $this->configService, $this->entityManager, $this->activityService, $this->metadataService)
            ->addUploadedFile(
                $job->sourceFilepath,
                $this->urlService,
                $job->originalFilename,
                $job->categories,
                $job->level,
                $job->imageId,
                $job->originalMd5sum,
            );
    }
}
