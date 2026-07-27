<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\TestErrorsController (`GET /__test/errors`,
 * public/__test_errors.php) -- test-mode-only error-drain route
 * (docs/PLAN-REPLAY-AUDIT.md finding #7). tests/Unit only exercises
 * Piwigo\Core\ErrorCollector::drain() directly (tests/Unit/Core/
 * ErrorCollectorTest.php); this controller class itself has no existing
 * Unit/Integration/Browser reference anywhere in the repo (confirmed via a
 * project-wide grep before writing this file), even though
 * IntegrationTestCase::assertNoPhpErrors() already calls the real route --
 * that hits the live Apache-served process, a different PHP process than
 * the one PHPUnit/Pest coverage instrumentation runs in, so the controller
 * class itself stays at 0% measured coverage until a real HTTP request
 * exercises it under the Browser suite's own coverage-visibility wiring.
 *
 * The class's own "outside test mode" 404 branch (`! Env::testModeIsActive()`)
 * is NOT exercised here: omitting the X-Piwigo-Env header makes
 * Bootstrap\RequestBootstrap load the real (non-test) `.env` instead of
 * `.env.test` -- this dev sandbox has no `.env` file at all (confirmed
 * live: a bare `curl` with no header 500s with an "Access denied for user
 * ''@'localhost'" DB-connection fatal from RequestBootstrap::configure(),
 * well before this controller's own body ever runs), so that branch is
 * untestable via a real HTTP request in this environment regardless of
 * which route is hit -- an environment-configuration gap, not something
 * this controller's own code can be exercised around.
 */

it('drains the ErrorCollector buffer as JSON when test mode is active', function (): void {
    $body = H::httpBody('__test/errors');

    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new \RuntimeException('expected a JSON object/array, got: ' . var_export($decoded, true));
    }
    expect($decoded)->toHaveKey('errors');
    $errors = $decoded['errors'];
    expect($errors)->toBeArray();

    // ErrorCollector::$collected is a static, in-process buffer (see
    // ErrorCollectorTest's own docblock) -- a fresh Apache/PHP-FPM worker
    // handling this bare GET starts with an empty buffer, and nothing in
    // this request's own handling chain should trigger a PHP warning/
    // notice, so a clean request drains to an empty list.
    expect($errors)->toBe([]);
});

it('returns a real JSON content-type header', function (): void {
    $ch = curl_init(H::baseUrl() . '/__test/errors');
    expect($ch)->not->toBeFalse();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Piwigo-Env: test']);
    $response = curl_exec($ch);
    unset($ch);
    expect($response)->toBeString();

    expect(strtolower((string) $response))->toContain('content-type: application/json');
});
