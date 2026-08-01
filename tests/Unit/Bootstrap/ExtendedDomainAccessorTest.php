<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;
use Piwigo\History\HistoryService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationService;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Search\SearchService;
use Piwigo\Section\SectionPopulator;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\ExtendedDomainAccessor -- 3 of its 11 typed accessors
 * (commentService()/permalinkService()/notificationByMailService()) had
 * zero coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1): the underlying container bindings ARE exercised (Piwigo\Tests\
 * Integration\ContainerSmokeTest resolves every config/container.php
 * entry directly), but nothing calls these 3 specific thin wrapper
 * methods. Matches ContainerSmokeTest's own "extends plain TestCase, no DB
 * needed to resolve a service" precedent -- these services are lazy DBAL
 * wrappers, not eager connections, at construction time.
 *
 * Every accessor's own "Container returned an unexpected type"
 * \LogicException guard was ALSO entirely uncovered (all 11 throw lines
 * red) -- see Piwigo\Tests\Support\KernelContainerOverride's own docblock
 * and AdminAccessorTest.php's identical shape for the other 4 sibling
 * accessor classes.
 */
beforeEach(function (): void {
    Kernel::reset();
    Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));
    // searchFilterRenderer()/sectionPopulator() additionally need
    // Piwigo\Template\CurrentTemplate seeded (a renderer dependency), even
    // though nothing here ever renders anything -- Template's own
    // constructor reads CurrentPaths (hence the real Paths passed into
    // Kernel::boot() just above) and, unless dataDirChecked is already
    // marked '1', tries a real DB write via CurrentConfigService, which
    // isn't booted here -- same "mark it already-checked" setup
    // TabsheetTest.php's own Template construction uses.
    \Piwigo\Config\CurrentConfig::setDataLocation('data/');
    \Piwigo\Config\CurrentConfig::setDataDirChecked('1');
    \Piwigo\Template\CurrentTemplate::set(new \Piwigo\Template\Template(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
    \Piwigo\Template\CurrentTemplate::reset();
    \Piwigo\Config\CurrentConfig::reset();
});

test('commentService resolves a real CommentService from the container', function (): void {
    expect(ExtendedDomainAccessor::commentService())->toBeInstanceOf(CommentService::class);
});

test('permalinkService resolves a real PermalinkService from the container', function (): void {
    expect(ExtendedDomainAccessor::permalinkService())->toBeInstanceOf(PermalinkService::class);
});

test('notificationByMailService resolves a real NotificationByMailService from the container', function (): void {
    expect(ExtendedDomainAccessor::notificationByMailService())->toBeInstanceOf(NotificationByMailService::class);
});

test('activityService resolves a real ActivityService from the container', function (): void {
    expect(ExtendedDomainAccessor::activityService())->toBeInstanceOf(ActivityService::class);
});

test('searchService resolves a real SearchService from the container', function (): void {
    expect(ExtendedDomainAccessor::searchService())->toBeInstanceOf(SearchService::class);
});

test('searchFilterRenderer resolves a real SearchFilterRenderer from the container', function (): void {
    expect(ExtendedDomainAccessor::searchFilterRenderer())->toBeInstanceOf(SearchFilterRenderer::class);
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

test('notificationService resolves a real NotificationService from the container', function (): void {
    expect(ExtendedDomainAccessor::notificationService())->toBeInstanceOf(NotificationService::class);
});

test('sectionPopulator resolves a real SectionPopulator from the container', function (): void {
    expect(ExtendedDomainAccessor::sectionPopulator())->toBeInstanceOf(SectionPopulator::class);
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

test('searchFilterRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        SearchFilterRenderer::class,
        static fn () => ExtendedDomainAccessor::searchFilterRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . SearchFilterRenderer::class);

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

test('notificationService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        NotificationService::class,
        static fn () => ExtendedDomainAccessor::notificationService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . NotificationService::class);

test('notificationByMailService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        NotificationByMailService::class,
        static fn () => ExtendedDomainAccessor::notificationByMailService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . NotificationByMailService::class);

test('permalinkService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PermalinkService::class,
        static fn () => ExtendedDomainAccessor::permalinkService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PermalinkService::class);

test('sectionPopulator throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        SectionPopulator::class,
        static fn () => ExtendedDomainAccessor::sectionPopulator()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . SectionPopulator::class);
