<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Controller\ImageDerivativeController;
use Piwigo\Image\ImageStdParams;

// Workstream C3 Part III: ImageDerivativeController's own trySwitchSource()
// (redirects to an already-cached *different* derivative type when it's an
// exact match for what's being requested) had no direct test coverage
// before this phase -- most of the class needs a real DB connection/
// filesystem/permission-checked request to exercise meaningfully, but its
// own URL-construction logic (derivativeUrlPath(), extracted specifically
// so this is possible) is pure and testable in isolation. Found live during
// Part III's own work: a raw filesystem-relative redirect built the same
// way was a real SEC-33-class bug (redirecting into the now-unreachable
// _data/i/ tree) -- this locks in the fix.

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
});

function callDerivativeUrlPath(string $urlSuffix, string $fromType, string $toType): string
{
    $method = new ReflectionMethod(ImageDerivativeController::class, 'derivativeUrlPath');

    /** @var string */
    return $method->invoke(null, $urlSuffix, $fromType, $toType);
}

test('derivativeUrlPath substitutes the type token and keeps the rest of the suffix unchanged', function (): void {
    Config::loadArray([
        'php_extension_in_urls' => true,
        'question_mark_in_urls' => true,
    ]);

    $result = callDerivativeUrlPath(
        'upload/2026/08/01/20260801000000-2e7ed83c-th.jpg',
        ImageStdParams::THUMB,
        ImageStdParams::XXSMALL,
    );

    expect($result)->toBe('i.php?/upload/2026/08/01/20260801000000-2e7ed83c-2s.jpg');
});

test('derivativeUrlPath omits .php when php_extension_in_urls is disabled', function (): void {
    Config::loadArray([
        'php_extension_in_urls' => false,
        'question_mark_in_urls' => true,
    ]);

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB);

    expect($result)->toBe('i?/foo-th.jpg');
});

test('derivativeUrlPath omits the leading ? when question_mark_in_urls is disabled', function (): void {
    Config::loadArray([
        'php_extension_in_urls' => true,
        'question_mark_in_urls' => false,
    ]);

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB);

    expect($result)->toBe('i.php/foo-th.jpg');
});

test('derivativeUrlPath prefixes the app-mount-relative i.php URL, unaware of the app root itself', function (): void {
    Config::loadArray([
        'php_extension_in_urls' => true,
        'question_mark_in_urls' => true,
    ]);

    // derivativeUrlPath() itself is deliberately root-agnostic -- the real
    // caller (trySwitchSource()) prefixes its return value with
    // UrlService::getAbsoluteRootUrl(false) separately, the same
    // depth-independent mount-path mechanism parseRequest()'s own
    // $this->srcUrl fix uses for the true-original redirect case.
    $result = callDerivativeUrlPath('upload/2026/08/01/foo-2s.jpg', ImageStdParams::XXSMALL, ImageStdParams::LARGE);

    expect($result)->toBe('i.php?/upload/2026/08/01/foo-la.jpg');
    expect($result)->not->toContain('..');
});
