<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// Legacy Coupling Retirement follow-up: Admin\ThemesStandardPagesPageRenderer
// used to store an absolute filesystem path in the
// standard_pages_selected_logo_path config value, rendered directly as an
// <img src> -- never a valid URL, and doubly broken once local/ became
// unreachable from public/. Piwigo\Controller\CustomLogoController
// (public/logo.php) now serves it instead.

const CUSTOM_LOGO_RELATIVE_PATH = 'logo/browser-test-logo.png';

afterEach(function (): void {
    H::clearCustomLogo(CUSTOM_LOGO_RELATIVE_PATH);
    H::setGuestTheme('modus');
});

it('serves the configured custom logo through logo.php and 404s when none is configured', function (): void {
    expect(H::httpStatus('logo.php'))->toBe(404);

    $png = H::makeTestPng();
    H::setCustomLogo(CUSTOM_LOGO_RELATIVE_PATH, $png);

    expect(H::httpStatus('logo.php'))->toBe(200);
    expect(H::httpBody('logo.php'))->toBe($png);
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
    // itself resolve, matching the real regression this fixes (previously
    // an absolute filesystem path, never a working URL).
    expect(H::httpStatus('logo.php'))->toBe(200);
});
