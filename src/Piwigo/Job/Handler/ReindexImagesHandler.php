<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 */
final readonly class ReindexImagesHandler
{
    public function __construct(
        private MetadataService $metadataService,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(ReindexImagesJob $job): void
    {
        $this->metadataService->syncMetadata($job->imageIds, $this->permissionService, $this->entityManager);
    }
}
