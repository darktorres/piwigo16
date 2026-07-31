<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
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
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

function callDerivativeUrlPath(string $urlSuffix, string $fromType, string $toType): string
{
    $method = new ReflectionMethod(ImageDerivativeController::class, 'derivativeUrlPath');

    /** @var string */
    return $method->invoke(null, $urlSuffix, $fromType, $toType);
}

test('derivativeUrlPath substitutes the type token and keeps the rest of the suffix unchanged', function (): void {
    CurrentConfig::setPhpExtensionInUrls(true);
    CurrentConfig::setQuestionMarkInUrls(true);

    $result = callDerivativeUrlPath(
        'upload/2026/08/01/20260801000000-2e7ed83c-th.jpg',
        ImageStdParams::THUMB,
        ImageStdParams::XXSMALL,
    );

    expect($result)->toBe('i.php?/upload/2026/08/01/20260801000000-2e7ed83c-2s.jpg');
});

test('derivativeUrlPath omits .php when php_extension_in_urls is disabled', function (): void {
    CurrentConfig::setPhpExtensionInUrls(false);
    CurrentConfig::setQuestionMarkInUrls(true);

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB);

    expect($result)->toBe('i?/foo-th.jpg');
});

test('derivativeUrlPath omits the leading ? when question_mark_in_urls is disabled', function (): void {
    CurrentConfig::setPhpExtensionInUrls(true);
    CurrentConfig::setQuestionMarkInUrls(false);

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB);

    expect($result)->toBe('i.php/foo-th.jpg');
});

test('derivativeUrlPath prefixes the app-mount-relative i.php URL, unaware of the app root itself', function (): void {
    CurrentConfig::setPhpExtensionInUrls(true);
    CurrentConfig::setQuestionMarkInUrls(true);

    // derivativeUrlPath() itself is deliberately root-agnostic -- the real
    // caller (trySwitchSource()) prefixes its return value with
    // UrlService::getAbsoluteRootUrl(false) separately, the same
    // depth-independent mount-path mechanism parseRequest()'s own
    // $this->srcUrl fix uses for the true-original redirect case.
    $result = callDerivativeUrlPath('upload/2026/08/01/foo-2s.jpg', ImageStdParams::XXSMALL, ImageStdParams::LARGE);

    expect($result)->toBe('i.php?/upload/2026/08/01/foo-la.jpg');
    expect($result)->not->toContain('..');
});

// parseCustomParams()'s own null-token guard: every real HTTP request path
// guarantees at least 2 tokens remain after the method's first
// array_shift() (its own `count($tokens) < 2` check a few lines up runs
// *before* the 2 further shift()s below), so a null $token there is
// provably unreachable from a real derivative URL -- the method still
// guards it explicitly rather than relying on assert() (a total no-op in
// this environment, zend.assertions=-1). @param string[] $tokens isn't
// enforced by PHP at runtime for a plain array literal, so reflection with
// a deliberately malformed array (a literal null third element, not just
// an absent one) is the only way to exercise this branch at all -- exactly
// the kind of defensive-guard case this method's own docblock describes.
test('parseCustomParams() 400s its own "impossible" null-token guard when invoked with a malformed token array', function (): void {
    \Piwigo\Core\CurrentLogger::set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));

    $controller = new ImageDerivativeController(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3)));
    $method = new ReflectionMethod(ImageDerivativeController::class, 'parseCustomParams');

    $exception = null;
    try {
        // '150x100' has no leading 's'/'e', forcing the 3-token branch;
        // 'a' is a valid crop-fraction token; the literal null in the 3rd
        // slot stands in for what only a non-HTTP caller could ever
        // produce.
        $method->invoke($controller, ['150x100', 'a', null]);
    } catch (\Piwigo\Http\ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(\Piwigo\Http\ResponseReadyException::class);
    if (! $exception instanceof \Piwigo\Http\ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(400)
        ->and((string) $response->getBody())->toBe('Sizing arr');

    \Piwigo\Core\CurrentLogger::reset();
});
