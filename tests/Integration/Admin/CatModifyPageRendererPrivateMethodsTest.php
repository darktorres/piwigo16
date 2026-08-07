<?php

declare(strict_types=1);

use Piwigo\Category\CategoryService;
use Piwigo\Admin\CatModifyPageRenderer;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Admin\CatModifyPageRenderer::getLocalDir()/getSiteUrl() are both
 * private, and both throw a real \Exception when their own category lookup
 * (CategoryService::getCategoryUppercatsById()/getGalleriesUrlForCategory())
 * returns null (no matching row). tests/Browser/CatModifyPageRendererTest.php's
 * own docblock explains why *that* file can't reach either branch: its only
 * real caller (AlbumSubController) already validates cat_id against a fresh
 * DB read immediately before dispatching into render(), so a category that
 * stops existing mid-request never happens over real HTTP.
 *
 * Both methods are reachable directly via reflection with an id that is
 * never a real row, though -- the same "private method, real DB,
 * ReflectionMethod" shape tests/Integration/Admin/
 * IntroSubControllerGetLatestNewsTest.php and tests/Integration/Admin/
 * Integrity/C13yInternalTest.php use for a sibling gap (the latter reaches
 * CoreDomainAccessor::userService(), which needs the DI container, so
 * Kernel::boot() is called explicitly here too -- idempotent, safe
 * regardless of what ran before it in this shared process).
 *
 * A real Paths is required, not a bare boot: CategoryService's constructor
 * takes Lang, whose Paths param the container can't guess without one.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    // Without this, Kernel stays booted with no real Paths bound for the
    // rest of this shared composer test:integration process -- the exact
    // "cross-file Kernel-state leak" bug class already known elsewhere in
    // this codebase (see e.g. C13yInternalTest.php's own identical
    // afterEach()), caught live: this file's own bare Kernel::boot() above
    // was silently leaking into whichever Integration test file happened
    // to run next, making its own real-Paths Kernel::boot() call a no-op.
    Kernel::reset();
});

function catModifyReflect(string $method, int|string $categoryId): string
{
    $reflected = new ReflectionMethod(CatModifyPageRenderer::class, $method);

    $categoryService = Kernel::container()->get(CategoryService::class);
    if (! $categoryService instanceof CategoryService) {
        throw new LogicException('Container returned an unexpected type for ' . CategoryService::class);
    }

    /** @var string */
    return $reflected->invoke(new CatModifyPageRenderer(), $categoryId, $categoryService);
}

test('getLocalDir throws when the category id matches no real row', function (): void {
    // Never a real piwigo_categories id in the fixture (tests/Fixtures/
    // piwigo-17.0.sql only seeds ids 1 and 2) or anything any other
    // Integration test file creates.
    $missingId = 999999;

    expect(fn () => catModifyReflect('getLocalDir', $missingId))
        ->toThrow(Exception::class, "getLocalDir(): category #{$missingId} not found");
});

test('getSiteUrl throws when the category id matches no real row', function (): void {
    $missingId = 999999;

    expect(fn () => catModifyReflect('getSiteUrl', $missingId))
        ->toThrow(Exception::class, "getSiteUrl(): category #{$missingId} not found");
});
