<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// Workstream C3 Part III: found live while verifying ImageDerivativeController's
// own conversion to a real, routed controller -- checkDerivativePermission()
// (called from inside a try { ... } catch (\Exception $e) { $logger->error(...); }
// block, itself calling ierror('Forbidden', 403)) used to be safe because
// ierror() called exit() directly, which bypasses any enclosing catch entirely.
// Converting ierror() to throw Piwigo\Http\ResponseReadyException (needed so
// this class could join the real pipeline) meant that same catch (\Exception)
// block silently swallowed the 403 and let execution continue as if nothing
// happened -- a real anonymous request for a private album's derivative was
// served (200) instead of denied. Fixed by re-throwing ResponseReadyException
// before the generic catch; this test locks the fix in, both for a
// not-yet-cached derivative (the "must generate" path) and an already-cached
// one (the SEC-33 fast path the class's own docblock singles out: "must run
// before EVERY fast-path exit below, not just the generate branch").
//
// Fixture shape (tests/Fixtures/piwigo-17.0.sql, see CategoryRepositoryTest's
// own docblock): category 2 "Nested Sub Album" has 2 direct images (4, 5),
// neither also in category 1 -- image 4 is file 20260801000000-3df68537.jpg.

afterEach(function (): void {
    // Always restore, even if an assertion above failed mid-test -- a
    // stuck-private category 2 would otherwise break every later test file
    // touching this fixture's own gallery-home/category-2 pages.
    H::setCategoryPrivate(2, private: false);
});

it('denies an anonymous request for a private album\'s not-yet-cached derivative', function (): void {
    // image 4 is 200x150 (see CategoryRepositoryTest's own fixture-shape
    // docblock) -- 'sq' (square, 120x120 max) genuinely needs cropping,
    // unlike a size at or above 200x150 (which would 301-redirect to the
    // true original via action.php instead of generating, a different
    // code path from the one this test targets).
    $path = 'i.php?/upload/2026/08/01/20260801000000-3df68537-sq.jpg';

    expect(H::httpStatus($path))->toBe(200);

    H::setCategoryPrivate(2, private: true);

    expect(H::httpStatus($path))->toBe(403);
});

it('denies an anonymous request for a private album\'s already-cached derivative', function (): void {
    $path = 'i.php?/upload/2026/08/01/20260801000000-3df68537-th.jpg';

    // Prime the cache while the album is still public.
    expect(H::httpStatus($path))->toBe(200);

    H::setCategoryPrivate(2, private: true);

    // [SEC-33]: the permission check must run on every request, cache hit
    // or not -- this is the exact fast path a raw exit() used to make safe
    // by construction and a bare throw does not, without the explicit
    // re-throw fix.
    expect(H::httpStatus($path))->toBe(403);

    H::setCategoryPrivate(2, private: false);

    expect(H::httpStatus($path))->toBe(200);
});
