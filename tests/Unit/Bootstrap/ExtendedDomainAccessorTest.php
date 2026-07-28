<?php

declare(strict_types=1);

use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Kernel;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Permalink\PermalinkService;

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
 */
beforeEach(function (): void {
    Kernel::reset();
    Kernel::boot();
});

afterEach(function (): void {
    Kernel::reset();
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
