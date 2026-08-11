<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Raw-HTML regression check: renders every route in VisualRegressionTest.php's
 * shared route list (Helpers/VisualRegressionRoutes.php) and asserts the raw
 * HTTP response body against tests/Fixtures/GoldenHtml/{route}.html -- a
 * finer-grained, markup-level check than VisualRegressionTest.php's
 * screenshot comparison can catch.
 *
 * MUST run in isolation, same reason as VisualRegressionTest.php: bundling
 * with the CRUD-mutating Browser tests drifts sidebar counts and other live
 * state, producing diffs unrelated to any real template change.
 *
 * Driven with plain curl, not Pest's Playwright-driven $page API -- this
 * captures the raw HTTP response body (what Template::fetchOutput() actually
 * produced), not Playwright's post-DOM-parse page.content(), which can
 * silently normalize markup (self-closing tags, attribute reordering) into
 * noise unrelated to a real template diff.
 *
 * `composer test:golden-html` runs the check: a route with no existing
 * baseline writes one (first capture); an existing baseline is compared
 * byte-for-byte after goldenHtmlNormalize() strips the one
 * legitimate source of cross-checkout noise (the configured
 * PIWIGO_BASE_URL path segment, which differs by whichever vhost served
 * the request) -- a real mismatch fails the test with both normalized
 * bodies to diff, and leaves the committed file untouched. Deliberately
 * accept a new baseline with `GOLDEN_HTML_UPDATE=1 composer
 * test:golden-html` only after reviewing the failure's diff.
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

/**
 * Neutralizes every known legitimate source of cross-checkout/cross-run
 * noise in an otherwise-deterministic capture, applied in sequence:
 *
 *  - Piwigo's own root-relative base path (`PIWIGO_BASE_URL`'s path
 *    component, e.g. `/piwigo17-4`), which differs by whichever
 *    checkout/vhost served the request and appears throughout the page
 *    (nav links, asset URLs). Detected per-document rather than passed
 *    in, so a fresh capture and a baseline captured under a *different*
 *    checkout's base path both normalize to the same placeholder
 *    independently -- same "environment-injected, never fixture-baked"
 *    treatment `FixtureNormalizer::apply()` already gives `galleries_url`.
 *    Two anchors, tried in order: the gallery theme's `<link rel="start">`
 *    Home link, then the admin theme's "Visit gallery" link (admin pages
 *    have no Home link at all) -- confirmed live: admin-photo-editor's
 *    base path leaked through unnormalized until this second anchor was
 *    added.
 *  - `_data/combined/*.{css,js}` bundle filenames: a content hash of
 *    `FileCombiner`'s own combine step, not template output -- differs
 *    run to run whenever the combined input set's mtimes/order differ,
 *    real template-content-identical or not.
 *  - `pwg_token`/anti-bot action tokens: a fresh per-session CSRF-style
 *    value (see `Piwigo\Auth\*`), never stable across two separate
 *    logins. Matched as a bare 64-hex-char string regardless of its
 *    surrounding syntax, confirmed live in at least 4 distinct shapes
 *    across real pages: `pwg_token=HEX` (URL query string),
 *    `name="pwg_token" value="HEX"` (hidden form input),
 *    `pwg_token = 'HEX'` / `pwg_token=" + "HEX` (JS assignment/
 *    concatenation) -- enumerating each syntactic wrapper individually
 *    proved a losing game; the token's fixed 64-char hex length is the
 *    one thing every shape shares, and nothing else on a real page is a
 *    bare 64-hex-char run.
 *  - `register.php`'s anti-bot `key` field
 *    (`value="{unix-timestamp}.{n}:{n}:{64-hex-char-hash}"`): a distinct
 *    time-seeded value, not a `pwg_token`, but the same class of
 *    never-stable-across-runs noise.
 *  - `serverId: '...'` (a bare 32-hex-char run, e.g. admin batch-manager's
 *    `CategoriesCache`/`TagsCache`/`GroupsCache`/`UsersCache` JS objects):
 *    a shorter, differently-generated session identifier than `pwg_token`,
 *    same non-stability reasoning.
 *  - `notification.php`'s RSS feed secret
 *    (`feed.php?feed=<50-char mixed-case alnum>`): regenerates on every
 *    request, never stable.
 *  - Search's saved-query key's *random suffix* only
 *    (`psk-<8-digit date>-<10-char mixed-case alnum>`, in the URL, the
 *    `<body>` id/class/`data-infos`, and a `search_id = '...'` JS
 *    assignment) -- the 10-char suffix regenerates every request and is
 *    normalized, but the leading 8-digit date is deliberately left alone:
 *    it should equal `PIWIGO_TEST_NOW`'s date on every run, and doesn't
 *    right now (confirmed live: shows the real wall-clock date instead,
 *    same real gap as `admin-batch`'s `date_creation` form default below)
 *    -- normalizing it away here would hide that finding instead of
 *    surfacing it on every future run.
 *
 * Deliberately NOT normalized at all: `random.php`'s photo ordering
 * (`list/4,3,1,2,5` vs. `list/5,1,2,3,4`) -- inherently random by design,
 * not a bug; that route cannot be byte-compared without the app itself
 * supporting a seeded-RNG test mode, which doesn't exist.
 *
 * The base path is replaced in both its plain and `rawurlencode()`-d form
 * (`%2F`-separated) -- confirmed live: `identification.php`'s quick-connect
 * form embeds it as a URL-encoded `redirect` hidden field
 * (`value="%2Fpiwigo17-sql%2Fabout.php"`), which the plain-text replacement
 * alone doesn't touch.
 */
function goldenHtmlNormalize(string $html): string
{
    $html = preg_replace('#(_data/combined/)[a-zA-Z0-9]+(\.(?:css|js))#', '$1{{HASH}}$2', $html) ?? $html;
    $html = preg_replace('#\b[a-f0-9]{32}(?:[a-f0-9]{32})?\b#', '{{TOKEN}}', $html) ?? $html;
    $html = preg_replace('#[0-9]{9,11}\.[0-9]+:[0-9]+:\{\{TOKEN\}\}#', '{{ANTIBOT_KEY}}', $html) ?? $html;
    $html = preg_replace('#feed=[A-Za-z0-9]{40,60}#', 'feed={{FEED_TOKEN}}', $html) ?? $html;
    $html = preg_replace('#(psk-[0-9]{8}-)[A-Za-z0-9]{10}#', '$1{{SEARCH_SUFFIX}}', $html) ?? $html;

    $basePath = null;
    if (preg_match('#<link rel="start" title="Home" href="(/[^"]*?)/?"#', $html, $m) === 1) {
        $basePath = $m[1];
    } elseif (preg_match('#<a href="(/[^"]*?)/?" class="visit-gallery#', $html, $m) === 1) {
        $basePath = $m[1];
    }
    if ($basePath === null) {
        return $html;
    }
    $basePath = rtrim($basePath, '/');
    if ($basePath === '') {
        return $html;
    }

    $html = str_replace($basePath, '{{BASE_PATH}}', $html);

    return str_replace(rawurlencode($basePath), '{{BASE_PATH}}', $html);
}

function goldenHtmlDiffDir(): string
{
    return goldenHtmlDir() . '/.diffs';
}

/**
 * Writes a real `diff -u` between the two normalized bodies to
 * tests/Fixtures/GoldenHtml/.diffs/{name}.diff -- a human reviewing a
 * failure can open this file directly instead of scrolling Pest's own
 * inline failure output (which truncates long bodies) or re-running the
 * suite. Gitignored: a generated-on-failure artifact, not committed
 * state, and overwritten (not accumulated) on every run.
 */
function goldenHtmlWriteDiff(string $name, string $normalizedFresh, string $normalizedExisting): string
{
    $dir = goldenHtmlDiffDir();
    if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
        throw new RuntimeException("Cannot create diff directory: {$dir}");
    }

    $existingTmp = tempnam(sys_get_temp_dir(), 'golden-existing-');
    $freshTmp = tempnam(sys_get_temp_dir(), 'golden-fresh-');
    if ($existingTmp === false || $freshTmp === false) {
        throw new RuntimeException('tempnam() failed');
    }
    file_put_contents($existingTmp, $normalizedExisting);
    file_put_contents($freshTmp, $normalizedFresh);

    $diffPath = $dir . "/{$name}.diff";
    exec(sprintf(
        'diff -u %s %s > %s',
        escapeshellarg($existingTmp),
        escapeshellarg($freshTmp),
        escapeshellarg($diffPath)
    ));

    unlink($existingTmp);
    unlink($freshTmp);

    return $diffPath;
}

/**
 * Real content-equality check against the committed baseline, replacing
 * the previous unconditional `file_put_contents()` (which always
 * overwrote the golden file and asserted nothing but HTTP 200 -- see this
 * file's own historical docblock note above, now corrected). No baseline
 * yet (a genuinely new route) writes one, matching "capture" semantics
 * for the first run; an existing baseline must match after
 * goldenHtmlNormalize() or the test fails, with a real diff file written
 * for review (see goldenHtmlWriteDiff() above) -- and the committed file
 * itself is left untouched either way. Reviewing and re-capturing a real
 * template change is a deliberate, separate step (see this file's own
 * top docblock), not a side effect of running the suite.
 */
function goldenHtmlAssertOrWrite(string $name, string $body): void
{
    $path = goldenHtmlDir() . "/{$name}.html";

    if (! is_file($path)) {
        file_put_contents($path, $body);

        return;
    }

    if (getenv('GOLDEN_HTML_UPDATE') === '1') {
        file_put_contents($path, $body);

        return;
    }

    $existing = file_get_contents($path);
    if ($existing === false) {
        throw new RuntimeException("Failed to read existing golden file: {$path}");
    }

    $normalizedFresh = goldenHtmlNormalize($body);
    $normalizedExisting = goldenHtmlNormalize($existing);

    $message = "{$name}'s golden HTML changed. Review the diff; if intentional, "
        . 're-run with GOLDEN_HTML_UPDATE=1 to accept the new baseline.';
    if ($normalizedFresh !== $normalizedExisting) {
        $diffPath = goldenHtmlWriteDiff($name, $normalizedFresh, $normalizedExisting);
        $message .= " Diff written to {$diffPath}.";
    }

    expect($normalizedFresh)->toBe($normalizedExisting, $message);
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

        goldenHtmlAssertOrWrite($name, $result['body']);
    })->group('golden-html-snapshot');
}

it("captures picture-1's golden HTML", function (): void {
    // Same hit-counter freeze as VisualRegressionTest.php's picture-1 --
    // images.hit ("Visited N times") increments on every view, including
    // this one, so it drifts on every run unless pinned first.
    H::freezeImageHits(1, 5);

    $result = goldenHtmlCurl('', '/picture.php?/1/category/1');

    expect($result['status'])->toBe(200, "picture-1 returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('picture-1', $result['body']);
})->group('golden-html-snapshot');

it("captures admin-photo-editor's golden HTML", function (): void {
    // Same hit-counter freeze as picture-1 -- the photo editor shows the
    // same "Visited N times" text.
    H::freezeImageHits(1, 5);

    $cookieJar = goldenHtmlLoginAsAdmin();
    $result = goldenHtmlCurl($cookieJar, '/admin.php?page=photo-1');
    unlink($cookieJar);

    expect($result['status'])->toBe(200, "admin-photo-editor returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('admin-photo-editor', $result['body']);
})->group('golden-html-snapshot');
