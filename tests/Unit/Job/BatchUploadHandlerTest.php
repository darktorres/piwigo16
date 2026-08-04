<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Job\Handler\BatchUploadHandler;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Url\UrlService;

/**
 * BatchUploadHandler::__invoke() is a 1-line delegate to
 * UploadService::addUploadedFile() -- its own docblock already documents
 * why the general case can't be constructor-injected/faked (UploadService
 * is final, no interface) and why its "new photo" branch specifically
 * needs a live Browser-tier context (a genuine self-fetchRemote() HTTP
 * call to force derivative generation, see UploadService::
 * addUploadedFileAddToCategories()). The *duplicate-detected* branch is
 * different: it returns early (addUploadedFile()'s own `return $image_id;`
 * right after `unlink($source_filepath)`) before that HTTP call is ever
 * reached, so it's exercised for real here against the fixture's own
 * photo #1 (md5sum 'fc6fccf1f8d70f6d7c6d627871f2ea6f') -- no fake/mock
 * needed, and this closes 100% of this handler's own coverage gap (its
 * entire body is one call expression + assert + return, all executed
 * together the moment any successful invocation runs).
 *
 * loungeActive is forced true so addUploadedFileAddToCategories()'s own
 * `! loungeActive()` branch (a DB COUNT(*) query, then a
 * CurrentConfigService-backed confUpdateParam() call once
 * loungeActivateThreshold, which defaults to 1, is met) never runs --
 * unrelated to what this test targets, and would otherwise need
 * CurrentConfigService wired for no reason (categories is null here, so
 * the rest of that method's body is skipped either way).
 */
function batch_upload_handler_test_current_logger(): CurrentLogger
{
    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    if (! $currentLogger instanceof CurrentLogger) {
        throw new \LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }

    return $currentLogger;
}

function batch_upload_handler_test_storage_registry(): StorageRegistry
{
    $storageRegistry = Kernel::container()->get(StorageRegistry::class);
    if (! $storageRegistry instanceof StorageRegistry) {
        throw new \LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
    }

    return $storageRegistry;
}

function batch_upload_handler_test_entity_manager(): EntityManagerInterface
{
    $entityManager = Kernel::container()->get(EntityManagerInterface::class);
    if (! $entityManager instanceof EntityManagerInterface) {
        throw new \LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
    }

    return $entityManager;
}

/**
 * Never actually read -- this suite forces loungeActive true specifically
 * so UploadService's own confUpdateParam() call (the only real reader of
 * this dependency) never runs, per this file's own docblock above.
 */
function batch_upload_handler_test_config_service(): \Piwigo\Config\ConfigService
{
    $configService = Kernel::container()->get(\Piwigo\Config\ConfigService::class);
    if (! $configService instanceof \Piwigo\Config\ConfigService) {
        throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\ConfigService::class);
    }

    return $configService;
}

function batch_upload_handler_test_activity_service(): \Piwigo\Activity\ActivityService
{
    $activityService = Kernel::container()->get(\Piwigo\Activity\ActivityService::class);
    if (! $activityService instanceof \Piwigo\Activity\ActivityService) {
        throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Activity\ActivityService::class);
    }

    return $activityService;
}

function batch_upload_handler_test_metadata_service(): \Piwigo\Metadata\MetadataService
{
    $metadataService = Kernel::container()->get(\Piwigo\Metadata\MetadataService::class);
    if (! $metadataService instanceof \Piwigo\Metadata\MetadataService) {
        throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Metadata\MetadataService::class);
    }

    return $metadataService;
}

beforeEach(function (): void {
    // A real Paths is required, not a bare boot: batch_upload_handler_test_
    // storage_registry() resolves StorageRegistry::class from the
    // container, whose own factory always calls fromConfig(), which
    // requires() config/storage.php -- that file unconditionally calls
    // CurrentPaths::get().
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    batch_upload_handler_test_current_logger()->set(new Logger(['severity' => Logger::OFF]));
    CurrentConfig::setLoungeActive(true);
});

afterEach(function (): void {
    Kernel::reset();
    CurrentConfig::reset();
});

test('__invoke returns the existing image id and deletes the newly uploaded file when its md5sum already exists (duplicate detection)', function (): void {
    // The duplicate-detection branch reads through Bootstrap\
    // CoreDomainAccessor::imageService(), so it needs a booted container --
    // same "boot right before, reset right after" convention as
    // UploadServiceTest's own container-touching cases.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    batch_upload_handler_test_current_logger()->set(new Logger(['severity' => Logger::OFF]));
    try {
        $sourceFilepath = sys_get_temp_dir() . '/piwigo-batch-upload-handler-test-' . bin2hex(random_bytes(8)) . '.jpg';
        file_put_contents($sourceFilepath, 'duplicate-upload-bytes');

        $handler = new BatchUploadHandler(new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), batch_upload_handler_test_current_logger(), batch_upload_handler_test_storage_registry(), \Piwigo\PluginConfig\EventDispatcher::get(), batch_upload_handler_test_config_service(), batch_upload_handler_test_entity_manager(), batch_upload_handler_test_activity_service(), batch_upload_handler_test_metadata_service());

        $imageId = $handler(new BatchUploadJob(
            sourceFilepath: $sourceFilepath,
            originalFilename: 'duplicate.jpg',
            categories: null,
            level: null,
            imageId: null,
            // Matches tests/Fixtures/piwigo-17.0.sql's own image #1
            // (fixture-photo-1.jpg) exactly -- triggers the "this md5sum
            // already exists" early-return branch instead of a real new-photo
            // insert.
            originalMd5sum: 'fc6fccf1f8d70f6d7c6d627871f2ea6f',
        ));

        expect($imageId)->toBe(1)
            ->and(file_exists($sourceFilepath))->toBeFalse();
    } finally {
        Kernel::reset();
    }
});
