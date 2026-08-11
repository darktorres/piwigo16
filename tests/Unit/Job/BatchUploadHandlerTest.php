<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\WsContext;
use Piwigo\Image\ImageService;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Job\Handler\BatchUploadHandler;
use Piwigo\Metadata\MetadataService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;

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
 * photo #1 (md5sum '2e7ee450c4a4cffe42945205029782b9') -- no fake/mock
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
        throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }

    return $currentLogger;
}

function batch_upload_handler_test_storage_registry(): StorageRegistry
{
    $storageRegistry = Kernel::container()->get(StorageRegistry::class);
    if (! $storageRegistry instanceof StorageRegistry) {
        throw new LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
    }

    return $storageRegistry;
}

function batch_upload_handler_test_entity_manager(): EntityManagerInterface
{
    $entityManager = Kernel::container()->get(EntityManagerInterface::class);
    if (! $entityManager instanceof EntityManagerInterface) {
        throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
    }

    return $entityManager;
}

/**
 * Never actually read -- this suite forces loungeActive true specifically
 * so UploadService's own confUpdateParam() call (the only real reader of
 * this dependency) never runs, per this file's own docblock above.
 */
function batch_upload_handler_test_config_service(): ConfigService
{
    $configService = Kernel::container()->get(ConfigService::class);
    if (! $configService instanceof ConfigService) {
        throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
    }

    return $configService;
}

function batch_upload_handler_test_activity_service(): ActivityService
{
    $activityService = Kernel::container()->get(ActivityService::class);
    if (! $activityService instanceof ActivityService) {
        throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
    }

    return $activityService;
}

function batch_upload_handler_test_metadata_service(): MetadataService
{
    $metadataService = Kernel::container()->get(MetadataService::class);
    if (! $metadataService instanceof MetadataService) {
        throw new LogicException('Container returned an unexpected type for ' . MetadataService::class);
    }

    return $metadataService;
}

function batch_upload_handler_test_image_service(): ImageService
{
    $imageService = Kernel::container()->get(ImageService::class);
    if (! $imageService instanceof ImageService) {
        throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
    }

    return $imageService;
}

function batch_upload_handler_test_current_config(): CurrentConfig
{
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    return $currentConfig;
}

function batch_upload_handler_test_ws_context(): WsContext
{
    $wsContext = Kernel::container()->get(WsContext::class);
    if (! $wsContext instanceof WsContext) {
        throw new LogicException('Container returned an unexpected type for ' . WsContext::class);
    }

    return $wsContext;
}

function batch_upload_handler_test_current_user(): CurrentUser
{
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    return $currentUser;
}

beforeEach(function (): void {
    // A real Paths is required, not a bare boot: batch_upload_handler_test_
    // storage_registry() resolves StorageRegistry::class from the
    // container, whose own factory always calls fromConfig(), which
    // requires() config/storage.php -- that file unconditionally calls
    // CurrentPathsTestFactory::get().
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    batch_upload_handler_test_current_logger()
        ->set(new Logger([
            'severity' => Logger::OFF,
        ]));
    batch_upload_handler_test_current_config()
        ->loungeActive = true;
});

afterEach(function (): void {
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
});

test('__invoke returns the existing image id and deletes the newly uploaded file when its md5sum already exists (duplicate detection)', function (): void {
    // The duplicate-detection branch reads through Bootstrap\
    // CoreDomainAccessor::imageService(), so it needs a booted container --
    // same "boot right before, reset right after" convention as
    // UploadServiceTest's own container-touching cases.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    batch_upload_handler_test_current_logger()
        ->set(new Logger([
            'severity' => Logger::OFF,
        ]));
    try {
        $sourceFilepath = sys_get_temp_dir() . '/piwigo-batch-upload-handler-test-' . bin2hex(random_bytes(8)) . '.jpg';
        file_put_contents($sourceFilepath, 'duplicate-upload-bytes');

        $handler = new BatchUploadHandler(LangTestFactory::get(), UrlServiceTestFactory::build(), batch_upload_handler_test_current_logger(), batch_upload_handler_test_storage_registry(), EventDispatcherTestFactory::get(), batch_upload_handler_test_config_service(), batch_upload_handler_test_entity_manager(), batch_upload_handler_test_activity_service(), batch_upload_handler_test_metadata_service(), batch_upload_handler_test_image_service(), batch_upload_handler_test_current_config(), batch_upload_handler_test_ws_context(), batch_upload_handler_test_current_user(), CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());

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
            originalMd5sum: '2e7ee450c4a4cffe42945205029782b9',
        ));

        expect($imageId)
            ->toBe(1)
            ->and(file_exists($sourceFilepath))
            ->toBeFalse();
    } finally {
        Kernel::reset();
    }
});
