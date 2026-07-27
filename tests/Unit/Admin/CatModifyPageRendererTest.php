<?php

declare(strict_types=1);

use Piwigo\Admin\CatModifyPageRenderer;

// CatModifyPageRenderer::render() itself needs a real DB connection,
// template engine, and populated $category array to exercise meaningfully
// (see tests/Browser/CatModifyPageRendererTest.php for that coverage), but
// its own getMinLocalDir() (abbreviates a long local directory path for
// display, e.g. "galleries/pets" -> "galleries/pets/&hellip;/1_year_old")
// is pure -- no DB/template/global state, just string math -- and directly
// testable in isolation via reflection, the same pattern
// tests/Unit/Controller/ImageDerivativeControllerTest.php uses for
// ImageDerivativeController::derivativeUrlPath().

function callGetMinLocalDir(?string $localDir): ?string
{
    $method = new ReflectionMethod(CatModifyPageRenderer::class, 'getMinLocalDir');

    /** @var ?string */
    return $method->invoke(new CatModifyPageRenderer(), $localDir);
}

test('getMinLocalDir returns a short path (3 or fewer segments) unchanged', function (): void {
    expect(callGetMinLocalDir('galleries/pets/rex'))->toBe('galleries/pets/rex');
});

test('getMinLocalDir abbreviates a long path to its first 2 and last segments', function (): void {
    // Matches this method's own docblock example verbatim: getCompleteDir(22)
    // for a category "pets > rex > 1_year_old" returns
    // "./galleries/pets/rex/1_year_old/" -- getMinLocalDir() is always called
    // by render() with the trailing slash already stripped (preg_replace('/\/$/', ...)
    // at the call site), so the input here has no trailing slash either.
    expect(callGetMinLocalDir('galleries/pets/rex/1_year_old'))
        ->toBe('galleries/pets/&hellip;/1_year_old');
});

test('getMinLocalDir keeps only the first 2 and very last segment for a deeply nested path', function (): void {
    expect(callGetMinLocalDir('galleries/pets/rex/puppies/1_year_old/vet_visits'))
        ->toBe('galleries/pets/&hellip;/vet_visits');
});

test('getMinLocalDir passes a null input straight through, not an empty string', function (): void {
    // (string) null casts to '' for explode()'s own count check (<=3), but
    // the branch returns the original $local_dir parameter, not the cast
    // value -- so null in means null out, never ''.
    expect(callGetMinLocalDir(null))->toBeNull();
});
