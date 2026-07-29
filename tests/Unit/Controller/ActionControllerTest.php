<?php

declare(strict_types=1);

use Piwigo\Controller\ActionController;

/**
 * ActionController::guessMimeType() is a pure extension -> mime-type
 * lookup with no DB/filesystem/session state -- directly testable via
 * reflection, the same pattern
 * tests/Unit/Admin/ThemesInstalledPageRendererTest.php uses for
 * compareThemes() and tests/Unit/Controller/ImageDerivativeControllerTest.php
 * uses for derivativeUrlPath().
 *
 * A full Browser-level HTTP round trip can never reach this method's
 * non-default match arms: __invoke() only consults it when the earlier
 * mime_content_type() lookup returned null, but mime_content_type()
 * reliably returns *some* non-false value -- even "application/x-empty"
 * for a zero-byte file -- for every real, readable local file this
 * controller ever serves (confirmed empirically against this environment's
 * own fileinfo build, not assumed), so guessMimeType() is only actually
 * consulted for a remote (http/https) source path, which __invoke() never
 * gets to exercise in this test suite without a real outbound network call.
 */
function callGuessMimeType(string $ext): string
{
    $method = new ReflectionMethod(ActionController::class, 'guessMimeType');
    $instance = new ReflectionClass(ActionController::class)->newInstanceWithoutConstructor();

    /** @var string */
    return $method->invoke($instance, $ext);
}

test('guessMimeType maps every jpeg-family extension to image/jpeg', function (): void {
    expect(callGuessMimeType('jpe'))->toBe('image/jpeg')
        ->and(callGuessMimeType('jpeg'))->toBe('image/jpeg')
        ->and(callGuessMimeType('jpg'))->toBe('image/jpeg');
});

test('guessMimeType maps both tiff-family extensions to image/tiff', function (): void {
    expect(callGuessMimeType('tiff'))->toBe('image/tiff')
        ->and(callGuessMimeType('tif'))->toBe('image/tiff');
});

test('guessMimeType maps both html-family extensions to text/html', function (): void {
    expect(callGuessMimeType('html'))->toBe('text/html')
        ->and(callGuessMimeType('htm'))->toBe('text/html');
});
