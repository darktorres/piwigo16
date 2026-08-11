<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P31 (Smarty -> Latte migration) baseline capture: renders every route in
 * VisualRegressionTest.php's shared route list (Helpers/VisualRegressionRoutes.php)
 * through the still-100%-Smarty engine and writes the raw HTML response body
 * to tests/Fixtures/GoldenHtml/{route}.html.
 *
 * MUST run in isolation, same reason as VisualRegressionTest.php: bundling
 * with the CRUD-mutating Browser tests drifts sidebar counts and other live
 * state, producing diffs unrelated to any real template change.
 *
 * Driven with plain curl, not Pest's Playwright-driven $page API -- this
 * captures the raw HTTP response body (what Template::fetchOutput() actually
 * produced), not Playwright's post-DOM-parse page.content(), which can
 * silently normalize markup (self-closing tags, attribute reordering) in
 * ways that would show up as noise unrelated to the real Smarty->Latte diff
 * this fixture exists to support.
 *
 * Every later P31 sub-item's own verification diffs its converted template's
 * newly-Latte-rendered output against its route's file here -- not a
 * byte-identical assertion (auto-escaping is deliberately enabled during the
 * migration, so some diffs are expected and reviewed, see docs/PLAN.md's P31
 * section) but the real "did anything besides escaping change" check VR's
 * own screenshot comparison is too coarse to catch.
 *
 * To (re)capture: `composer test:golden-html`. Only re-run this once a
 * template's own conversion sub-item has confirmed its diff against the
 * existing golden file is fully accounted for -- regenerating unconditionally
 * would silently erase the parity baseline the diff exists to check against.
 */

/**
 * @return array{status: int, body: string}
 */
function goldenHtmlCurl(string $cookieJar, string $path): array
{
    $ch = curl_init(H::baseUrl() . $path);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookieJar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Logs in as the fixture admin via the WS API (the same code path Piwigo
 * uses internally) rather than a raw form POST -- matches
 * RegenerateFixtureTest.php's own established preference: avoids flaky
 * form-login mechanics (anti-bot key timing, CSRF token scraping) that have
 * nothing to do with what this capture is checking. Returns a fresh cookie
 * jar path holding the resulting session; caller is responsible for
 * unlink()ing it.
 */
function goldenHtmlLoginAsAdmin(): string
{
    $cookieJar = tempnam(sys_get_temp_dir(), 'piwigo-golden-html-');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam() failed');
    }

    $ch = curl_init(H::baseUrl() . '/ws.php?format=json');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'method' => 'pwg.session.login',
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    $body = curl_exec($ch);
    unset($ch);

    if (! is_string($body)) {
        unlink($cookieJar);

        throw new RuntimeException('pwg.session.login returned no body');
    }
    $decoded = json_decode($body, true);
    if (! is_array($decoded) || ($decoded['stat'] ?? null) !== 'ok') {
        unlink($cookieJar);

        throw new RuntimeException('pwg.session.login failed: ' . $body);
    }

    return $cookieJar;
}

function goldenHtmlDir(): string
{
    return dirname(__DIR__) . '/Fixtures/GoldenHtml';
}

/** @var array<string, array{0: string, 1: bool}> $routes */
$routes = require __DIR__ . '/Helpers/VisualRegressionRoutes.php';

foreach ($routes as $name => [$path, $needsAuth]) {
    it("captures {$name}'s golden HTML", function () use ($name, $path, $needsAuth): void {
        $cookieJar = '';

        if ($needsAuth) {
            $cookieJar = goldenHtmlLoginAsAdmin();

            if ($name === 'admin-history') {
                // Same reasoning as VisualRegressionTest.php: wipe the table
                // this page queries, after login (which itself logs a
                // history row), so the default (today, unfiltered) search
                // is always empty and the capture is deterministic.
                H::truncateHistory();
            }
        }

        $result = goldenHtmlCurl($cookieJar, $path);

        if ($cookieJar !== '') {
            unlink($cookieJar);
        }

        expect($result['status'])->toBe(200, "{$name} ({$path}) returned HTTP {$result['status']}, expected 200");

        file_put_contents(goldenHtmlDir() . "/{$name}.html", $result['body']);
    })->group('golden-html-snapshot');
}

it("captures picture-1's golden HTML", function (): void {
    // Same hit-counter freeze as VisualRegressionTest.php's picture-1 --
    // images.hit ("Visited N times") increments on every view, including
    // this one, so it drifts on every run unless pinned first.
    H::freezeImageHits(1, 5);

    $result = goldenHtmlCurl('', '/picture.php?/1/category/1');

    expect($result['status'])->toBe(200, "picture-1 returned HTTP {$result['status']}, expected 200");

    file_put_contents(goldenHtmlDir() . '/picture-1.html', $result['body']);
})->group('golden-html-snapshot');

it("captures admin-photo-editor's golden HTML", function (): void {
    // Same hit-counter freeze as picture-1 -- the photo editor shows the
    // same "Visited N times" text.
    H::freezeImageHits(1, 5);

    $cookieJar = goldenHtmlLoginAsAdmin();
    $result = goldenHtmlCurl($cookieJar, '/admin.php?page=photo-1');
    unlink($cookieJar);

    expect($result['status'])->toBe(200, "admin-photo-editor returned HTTP {$result['status']}, expected 200");

    file_put_contents(goldenHtmlDir() . '/admin-photo-editor.html', $result['body']);
})->group('golden-html-snapshot');
