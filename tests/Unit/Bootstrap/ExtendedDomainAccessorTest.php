<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;
use Piwigo\History\HistoryService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchService;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\ExtendedDomainAccessor -- singleton/service-locator
 * elimination campaign, Phase 6: 5 of its original 11 typed accessors
 * (searchFilterRenderer()/notificationService()/notificationByMailService()/
 * permalinkService()/sectionPopulator()) were deleted once every real
 * caller converted to constructor injection, leaving zero remaining callers
 * for those 5 -- the other 6 (activityService()/commentService()/
 * searchService()/metadataService()/historyService()/rateService()) stay,
 * each still reached from at least one Ws/Pwg*.php static-dispatch call
 * site (Phase-10-locked) or config/messenger.php/Admin/Install's own
 * genuinely-static-context callers.
 *
 * commentService()'s own "zero coverage" gap (see /home/torres/.claude/
 * plans/piped-enchanting-spark.md, Wave 1) is closed below -- the
 * underlying container binding IS exercised elsewhere (Piwigo\Tests\
 * Integration\ContainerSmokeTest resolves every config/container.php entry
 * directly), but nothing called this specific thin wrapper method before.
 * Matches ContainerSmokeTest's own "extends plain TestCase, no DB needed to
 * resolve a service" precedent -- these services are lazy DBAL wrappers,
 * not eager connections, at construction time.
 *
 * Every remaining accessor's own "Container returned an unexpected type"
 * \LogicException guard is covered too -- see Piwigo\Tests\Support\
 * KernelContainerOverride's own docblock and AdminAccessorTest.php's
 * identical shape for the other sibling accessor classes.
 */
beforeEach(function (): void {
    Kernel::reset();
    Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('activityService resolves a real ActivityService from the container', function (): void {
    expect(ExtendedDomainAccessor::activityService())->toBeInstanceOf(ActivityService::class);
});

test('commentService resolves a real CommentService from the container', function (): void {
    expect(ExtendedDomainAccessor::commentService())->toBeInstanceOf(CommentService::class);
});

test('searchService resolves a real SearchService from the container', function (): void {
    expect(ExtendedDomainAccessor::searchService())->toBeInstanceOf(SearchService::class);
});

test('metadataService resolves a real MetadataService from the container', function (): void {
    expect(ExtendedDomainAccessor::metadataService())->toBeInstanceOf(MetadataService::class);
});

test('historyService resolves a real HistoryService from the container', function (): void {
    expect(ExtendedDomainAccessor::historyService())->toBeInstanceOf(HistoryService::class);
});

test('rateService resolves a real RateService from the container', function (): void {
    expect(ExtendedDomainAccessor::rateService())->toBeInstanceOf(RateService::class);
});

test('activityService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ActivityService::class,
        static fn () => ExtendedDomainAccessor::activityService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ActivityService::class);

test('commentService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CommentService::class,
        static fn () => ExtendedDomainAccessor::commentService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CommentService::class);

test('searchService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        SearchService::class,
        static fn () => ExtendedDomainAccessor::searchService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . SearchService::class);

test('metadataService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MetadataService::class,
        static fn () => ExtendedDomainAccessor::metadataService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MetadataService::class);

test('historyService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        HistoryService::class,
        static fn () => ExtendedDomainAccessor::historyService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . HistoryService::class);

test('rateService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        RateService::class,
        static fn () => ExtendedDomainAccessor::rateService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . RateService::class);
