<?php

declare(strict_types=1);

use Piwigo\Core\Paths;
use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Metadata\MetadataService;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\ExtendedDomainAccessor -- same "26/26 AdminAccessor" gap
 * shape, but for this class's own remaining 2 accessors. Phase 6's own
 * ExtendedDomainAccessor sub-batch deleted 5 of its original 11 typed
 * accessors (searchFilterRenderer()/notificationService()/
 * notificationByMailService()/permalinkService()/sectionPopulator()) once
 * every real caller converted to constructor injection; Phase 10 deleted a
 * 6th (historyService()) once PwgCore's own conversion emptied its last
 * remaining caller, then a 7th-9th (commentService()/searchService()/
 * rateService()) once PwgImages, their last remaining `src/Piwigo` caller,
 * converted too -- deleted the same way, along with their own dedicated
 * tests. activityService() and metadataService() both stay:
 * activityService()'s only remaining callers are Admin/Install/
 * {InstallWizard,InstallService}.php's own genuinely-static-context install
 * flow; metadataService()'s only remaining caller is config/messenger.php
 * (outside `src/Piwigo`, and deliberately outside the `Kernel::container()`
 * arch-test boundary too) -- a real, missed caller the initial
 * PwgImages-sub-batch trim didn't catch (its own "grep src/" sweep doesn't
 * reach `config/`), found only once the full-repo PHPStan pass ran.
 *
 * The container really does resolve the right type on every real call site
 * elsewhere in the codebase, so the "unexpected type" \LogicException
 * guard itself had zero coverage. See AdminAccessorTest.php's own
 * docblock for the KernelContainerOverride rationale.
 */
afterEach(function (): void {
    Kernel::reset();
});

test('activityService resolves a real ActivityService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    expect(ExtendedDomainAccessor::activityService())->toBeInstanceOf(ActivityService::class);
});

test('activityService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ActivityService::class,
        static fn () => ExtendedDomainAccessor::activityService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ActivityService::class);

test('metadataService resolves a real MetadataService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    expect(ExtendedDomainAccessor::metadataService())->toBeInstanceOf(MetadataService::class);
});

test('metadataService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MetadataService::class,
        static fn () => ExtendedDomainAccessor::metadataService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MetadataService::class);
