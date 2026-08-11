<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbCredentials;
use Piwigo\Image\ImageService;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 *
 * Delegates to UploadService::addUploadedFile(), constructed inline
 * rather than constructor-injected like this class's siblings
 * (GenerateDerivativeHandler/RegenerateAllDerivativesHandler/
 * ReindexImagesHandler): UploadService is `final` (no subclass test
 * double) with no interface (no mock seam), and its real execution ends
 * with a genuine self-fetchRemote() HTTP call back into the running app
 * to force derivative-cache generation -- only a live Browser-tier
 * context can safely exercise it. Extracting a proper, independently
 * testable service abstraction here would require a larger refactor
 * (tus upload support) and is out of scope for this job handler.
 */
final readonly class BatchUploadHandler
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private StorageRegistry $storageRegistry,
        private EventDispatcher $eventDispatcher,
        private ConfigService $configService,
        private EntityManagerInterface $entityManager,
        private ActivityService $activityService,
        private MetadataService $metadataService,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private WsContext $wsContext,
        private CurrentUser $currentUser,
        private Paths $paths,
        private DbCredentials $dbCredentials,
    ) {}

    public function __invoke(BatchUploadJob $job): int
    {
        return new UploadService($this->lang, $this->currentLogger, $this->storageRegistry, $this->eventDispatcher, $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, $this->paths, $this->dbCredentials)
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
