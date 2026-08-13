<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;

/**
 * Piwigo\Job\Handler\ReindexImagesHandler -- a thin delegate to
 * MetadataService::syncMetadata(). No dedicated Integration/Browser
 * spec of its own.
 *
 * An empty $imageIds job short-circuits MetadataRepository::
 * findImagesByIds()'s own `if ($ids === []) { return []; }` guard
 * before any real image row is read, and TagService::setTagsOf()'s own
 * `if (count($tagsOf) === 0) { return; }` guard before any real DB
 * write -- same "don't stub/exercise what would kill the test"
 * reasoning as SendNotificationEmailHandlerTest.php's own empty-$to
 * job. Resolved via Kernel::container()->get() since MetadataService
 * itself has several further constructor deps not otherwise needed
 * here.
 */
test('__invoke delegates to MetadataService::syncMetadata with the job\'s image ids', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    try {
        $metadataService = Kernel::container()->get(MetadataService::class);
        if (! $metadataService instanceof MetadataService) {
            throw new LogicException('Container returned an unexpected type for ' . MetadataService::class);
        }
        $permissionService = Kernel::container()->get(PermissionService::class);
        if (! $permissionService instanceof PermissionService) {
            throw new LogicException('Container returned an unexpected type for ' . PermissionService::class);
        }
        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }

        $handler = new ReindexImagesHandler($metadataService, $permissionService, $entityManager);

        $handler(new ReindexImagesJob([]));
    } finally {
        Kernel::reset();
    }
})->throwsNoExceptions();
