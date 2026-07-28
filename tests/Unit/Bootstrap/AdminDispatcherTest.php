<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

/**
 * Piwigo\Bootstrap\AdminDispatcher::dispatch() -- had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave
 * 1). The happy path (a mapped page slug reaching a real controller) is
 * already exhaustively exercised by every one of the Browser suite's
 * admin.php page-load tests; this file covers only the class's own docblock-
 * documented "programming error, not user input" guard -- unreachable
 * through the real admin.php entry point (which always resolves to a
 * mapped slug or the 'intro' fallback before calling dispatch()), but a
 * real, reachable branch for any direct caller of this public static
 * method.
 */
test('dispatch throws for a page slug not registered in config/admin_pages.php', function (): void {
    // AdminDispatcher::map() reads config/admin_pages.php off
    // CurrentPaths::get()->root -- a shared, process-wide static -- set
    // explicitly rather than relying on some earlier-run test file to
    // have already done so.
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));

    expect(fn () => AdminDispatcher::dispatch('not-a-real-registered-slug', new ServerRequest('GET', '/admin.php')))
        ->toThrow(LogicException::class, "Admin page 'not-a-real-registered-slug' is not registered in config/admin_pages.php.");
});
