<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// checkDerivativePermission() is called from inside a
// try { ... } catch (\Exception $e) { $logger->error(...); } block that
// itself calls ierror('Forbidden', 403), which throws
// Piwigo\Http\ResponseReadyException. That exception must be re-thrown
// before the generic catch (\Exception) block -- otherwise the catch
// silently swallows the 403 and lets execution continue as if nothing
// happened, serving a private album's derivative (200) to an anonymous
// request instead of denying it. This test locks that in, both for a
// not-yet-cached derivative (the "must generate" path) and an
// already-cached one (the SEC-33 fast path the class's own docblock
// singles out: "must run before EVERY fast-path exit below, not just the
// generate branch").
//
// Fixture shape (tests/Fixtures/piwigo-17.0.sql, see CategoryRepositoryTest's
// own docblock): category 2 "Nested Sub Album" has 2 direct images (4, 5),
// neither also in category 1 -- image 4's path is looked up via
// H::imagePath() below, not hardcoded: the filename's random content-hash
// suffix is freshly generated every time the fixture is regenerated (only
// the date-based directory portion is stable, from Env::now()'s frozen
// test clock).

/**
 * Inserts a derivative size suffix ('sq', 'th', ...) before an image path's
 * extension, e.g. 'upload/2026/08/01/X.jpg' -> 'upload/2026/08/01/X-sq.jpg'.
 */
function derivativePath(string $imagePath, string $suffix): string
{
    $withoutExt = preg_replace('/\.\w+$/', '', $imagePath);
    if (! is_string($withoutExt)) {
        throw new RuntimeException("derivativePath(): preg_replace() failed for '{$imagePath}'");
    }

    return $withoutExt . '-' . $suffix . '.jpg';
}

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
    $path = 'i.php?/' . derivativePath(H::imagePath(4), 'sq');

    expect(H::httpStatus($path))->toBe(200);

    H::setCategoryPrivate(2, private: true);

    expect(H::httpStatus($path))->toBe(403);
});

it('denies an anonymous request for a private album\'s already-cached derivative', function (): void {
    $path = 'i.php?/' . derivativePath(H::imagePath(4), 'th');

    // Prime the cache while the album is still public.
    expect(H::httpStatus($path))->toBe(200);

    H::setCategoryPrivate(2, private: true);

    // [SEC-33]: the permission check must run on every request, cache hit
    // or not -- ResponseReadyException must be re-thrown before the generic
    // catch (\Exception) block, or this exact fast path serves the
    // derivative instead of denying it.
    expect(H::httpStatus($path))->toBe(403);

    H::setCategoryPrivate(2, private: false);

    expect(H::httpStatus($path))->toBe(200);
});
