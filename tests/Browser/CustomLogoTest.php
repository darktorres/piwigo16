<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// Piwigo\Controller\CustomLogoController (public/logo.php) serves the
// configured custom logo. standard_pages_selected_logo_path stores a path
// on the 'local' disk, which is not reachable from public/ directly.

const CUSTOM_LOGO_RELATIVE_PATH = 'logo/browser-test-logo.png';

afterEach(function (): void {
    H::clearCustomLogo(CUSTOM_LOGO_RELATIVE_PATH);
    H::setGuestTheme('default');
});

it('serves the configured custom logo through logo.php and 404s when none is configured', function (): void {
    expect(H::httpStatus('logo.php'))->toBe(404);

    $png = H::makeTestPng();
    H::setCustomLogo(CUSTOM_LOGO_RELATIVE_PATH, $png);

    expect(H::httpStatus('logo.php'))->toBe(200);
    expect(H::httpBody('logo.php'))->toBe($png);
});

/**
 * Closes the `! $disk->fileExists($path)` 404 branch (~line 56) --
 * distinct from the "none is configured" 404 above (`standardPagesSelectedLogoPath()`
 * itself null/empty, ~line 50): here the config path is real and
 * non-empty, but nothing exists on the 'local' disk at that path (e.g. a
 * theme switch or a manual file deletion after the config was set).
 */
it('404s when a real, non-empty logo path is configured but no file exists on disk', function (): void {
    $png = H::makeTestPng();
    H::setCustomLogo(CUSTOM_LOGO_RELATIVE_PATH, $png);
    expect(H::httpStatus('logo.php'))->toBe(200);

    // Delete the on-disk file directly (bypassing clearCustomLogo(), which
    // would also clear the config rows) -- config still points at
    // CUSTOM_LOGO_RELATIVE_PATH, but the file itself is now gone.
    @unlink(dirname(__DIR__, 2) . '/local/' . CUSTOM_LOGO_RELATIVE_PATH);

    expect(H::httpStatus('logo.php'))->toBe(404);
    expect(H::httpBody('logo.php'))->toContain('Not found');
});

it('renders a working custom-logo <img> on the identification page once the standard_pages theme is active', function (): void {
    H::setGuestTheme('standard_pages');
    H::setCustomLogo(CUSTOM_LOGO_RELATIVE_PATH, H::makeTestPng());

    $html = H::httpBody('identification.php');

    // {$ROOT_URL} is empty at mount depth 0 (matches the sibling default-
    // logo line's own "themes/standard_pages/images/..." unprefixed src,
    // confirmed live) -- so this renders as the bare relative "logo.php",
    // resolving against identification.php's own directory (the site root
    // in this deployment).
    expect($html)->toContain('id="custom-logo" src="logo.php"');

    // Not just present in markup -- the src the page actually renders must
    // itself resolve to a working URL, not a raw filesystem path.
    expect(H::httpStatus('logo.php'))->toBe(200);
});
