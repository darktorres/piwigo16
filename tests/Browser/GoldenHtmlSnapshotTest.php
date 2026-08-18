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
 * Same shape as goldenHtmlCurl(), but a form POST -- for routes whose
 * interesting output only renders in response to a submitted form
 * (identification.php's bad-credentials error banner, specifically).
 *
 * @param array<string, string> $fields
 * @return array{status: int, body: string}
 */
function goldenHtmlCurlPost(string $cookieJar, string $path, array $fields): array
{
    $ch = curl_init(H::baseUrl() . $path);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
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
 * Logs in as the fixture admin via the real `/api/v1` session API (the same
 * code path Piwigo uses internally) rather than a raw form POST -- matches
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

    $body = H::curlApi($cookieJar, 'POST', '/api/v1/session', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
    ]);

    if ($body === '') {
        unlink($cookieJar);

        throw new RuntimeException('POST /api/v1/session returned no body');
    }
    // A failed login returns an RFC 9457 problem+json body, which also
    // happens to carry its own 'status' key (the HTTP status code) --
    // 'username' is SessionStatusPresenter's own field, only ever present
    // on a real success body, so it's the real discriminator here.
    $decoded = json_decode($body, true);
    if (! is_array($decoded) || ! isset($decoded['username'])) {
        unlink($cookieJar);

        throw new RuntimeException('POST /api/v1/session login failed: ' . $body);
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
 *  - The checkout's own absolute filesystem path (`{{ROOT_PATH}}`), which
 *    real pages print rather than merely link: a site's `galleries_url` is
 *    seeded as an absolute path, so admin-site-manager/admin-site-update
 *    render it in full. Baselines captured in one worktree otherwise carry
 *    that worktree's `$HOME`-rooted path and fail everywhere else --
 *    confirmed live, with baselines captured under `piwigo17-rewrite-4`
 *    failing in `-2` on nothing but the path. Taken from `__DIR__`, not
 *    from config, so it holds for another checkout, another user's home,
 *    or a CI runner just the same.
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
    // EphemeralKeyService::generate()'s round(microtime(true), 1) drops the
    // fractional part in string context whenever it rounds to a whole
    // second (~10% of real requests, confirmed live: PHP stringifies
    // round(1786654287.04, 1) as "1786654287", no trailing ".0") -- the
    // decimal group has to be optional, not assumed always-present.
    $html = preg_replace('#[0-9]{9,11}(?:\.[0-9]+)?:[0-9]+:\{\{TOKEN\}\}#', '{{ANTIBOT_KEY}}', $html) ?? $html;
    $html = preg_replace('#feed=[A-Za-z0-9]{40,60}#', 'feed={{FEED_TOKEN}}', $html) ?? $html;
    $html = preg_replace('#(psk-[0-9]{8}-)[A-Za-z0-9]{10}#', '$1{{SEARCH_SUFFIX}}', $html) ?? $html;

    // This checkout's own absolute filesystem path, which real pages do
    // print: a site's `galleries_url` is seeded as an absolute path by
    // InstallWizard, so admin-site-manager/admin-site-update render it
    // verbatim. Derived from __DIR__ rather than any config value, so it
    // holds wherever the checkout lives -- another worktree, another user's
    // home, or a CI runner.
    $rootPath = rtrim(dirname(__DIR__, 2), '/');
    if ($rootPath !== '') {
        $html = str_replace($rootPath, '{{ROOT_PATH}}', $html);
        $html = str_replace(rawurlencode($rootPath), '{{ROOT_PATH}}', $html);
    }

    $basePath = null;
    if (preg_match('#<link\s+rel="start"\s+title="Home"\s+href="(/[^"]*?)/?"#', $html, $m) === 1) {
        $basePath = $m[1];
    } elseif (preg_match('#<a\s+href="(/[^"]*?)/?"\s+class="visit-gallery#', $html, $m) === 1) {
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

    $normalizedFresh = goldenHtmlNormalize($body);

    // The baseline is stored already-normalized, so the committed file is
    // exactly what gets asserted rather than a raw capture with a
    // transformation hidden between it and the comparison. That keeps the
    // fixture stable across checkouts: regenerating it in another worktree,
    // on another machine, or in CI produces a byte-identical file for an
    // unchanged page, so any real diff in review is a real behaviour change
    // -- not a different $HOME, a fresh CSRF token or a new bundle hash.
    // Reading an existing baseline still normalizes it, which is a no-op for
    // a current file. That read-side pass only rewrites values belonging to
    // *this* checkout, though -- a legacy raw baseline captured elsewhere
    // carries a root path this checkout can't recognise, so it has to be
    // regenerated once rather than self-healing.
    if (getenv('GOLDEN_HTML_UPDATE') === '1') {
        file_put_contents($path, $normalizedFresh);

        return;
    }

    $existing = file_get_contents($path);
    if ($existing === false) {
        throw new RuntimeException("Failed to read existing golden file: {$path}");
    }

    $normalizedExisting = goldenHtmlNormalize($existing);

    $message = "{$name}'s golden HTML changed. Review the diff; if intentional, "
        . 're-run with GOLDEN_HTML_UPDATE=1 to accept the new baseline.';
    if ($normalizedFresh !== $normalizedExisting) {
        $diffPath = goldenHtmlWriteDiff($name, $normalizedFresh, $normalizedExisting);
        $message .= " Diff written to {$diffPath}.";
    }

    expect($normalizedFresh)
        ->toBe($normalizedExisting, $message);
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

it("captures slideshow's golden HTML", function (): void {
    // Same hit-counter freeze as picture-1 -- slideshow=1/category/1 is a
    // real single-picture view through picture.php, so it increments
    // images.hit just like picture-1 does. Kept out of
    // VisualRegressionRoutes.php's shared loop for the same reason
    // picture-1/admin-photo-editor are: a route that mutates state on
    // every visit can't share an unordered array with routes whose
    // baselines assume that state stays untouched.
    H::freezeImageHits(1, 5);

    $result = goldenHtmlCurl('', '/picture.php?/1/category/1&slideshow=');

    expect($result['status'])->toBe(200, "slideshow returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('slideshow', $result['body']);
})->group('golden-html-snapshot');

it("captures infos-errors's golden HTML", function (): void {
    // infos_errors.latte only renders when HtmlService::flushKeyedErrors()
    // has something to show it -- IdentificationController's bad-credentials
    // branch ($errors['login_form_error'], no redirect) is the simplest real
    // trigger. IdentificationSubmitRequest::fromArrays() needs no CSRF/
    // anti-bot token, but does require an existing session cookie (line
    // ~113's `$has_session_cookie` check) or a different error
    // ('Cookies are blocked...') renders instead -- a plain GET first
    // establishes one, same cookie jar reused for the POST.
    $cookieJar = tempnam(sys_get_temp_dir(), 'piwigo-golden-html-');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam() failed');
    }

    goldenHtmlCurl($cookieJar, '/identification.php');

    $result = goldenHtmlCurlPost($cookieJar, '/identification.php', [
        'login' => '1',
        'username' => 'golden-html-nonexistent-user',
        'password' => 'wrong-password',
    ]);
    unlink($cookieJar);

    expect($result['status'])->toBe(200, "infos-errors returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('infos-errors', $result['body']);
})->group('golden-html-snapshot');

/**
 * Template::setTheme()'s standard_pages fallback only fires for a
 * non-'default' CurrentUser theme (guest user id 2 for these 3 anonymous
 * routes) on exactly identification/register/password -- the fixture has
 * no second real gallery theme installed, so themes/golden_html_test/
 * (a themeconf.inc.php-only stub, no template overrides) exists purely to
 * give Template::setTheme() something real to load before it swaps to
 * the real themes/standard_pages directory. H::setUserTheme() mutates the
 * shared guest row every other anonymous route's baseline also depends on
 * rendering under 'default' -- restored in `finally` immediately after
 * the request, matching this file's own narrow-DB-slice-freeze precedent
 * (H::freezeImageHits()/H::truncateHistory()).
 *
 * @param 'identification'|'register'|'password' $routeName
 */
function goldenHtmlCapturesStandardPages(string $routeName, string $path): void
{
    $guestUserId = 2;
    $previousTheme = H::userTheme($guestUserId);
    expect($previousTheme)
        ->not->toBeNull("fixture guest user (id {$guestUserId}) has no user_infos row");

    H::setUserTheme($guestUserId, 'golden_html_test');

    try {
        $result = goldenHtmlCurl('', $path);
    } finally {
        H::setUserTheme($guestUserId, $previousTheme ?? 'default');
    }

    expect($result['status'])->toBe(200, "standard-pages-{$routeName} returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite("standard-pages-{$routeName}", $result['body']);
}

it("captures standard-pages-identification's golden HTML", function (): void {
    goldenHtmlCapturesStandardPages('identification', '/identification.php');
})->group('golden-html-snapshot');

it("captures standard-pages-register's golden HTML", function (): void {
    goldenHtmlCapturesStandardPages('register', '/register.php');
})->group('golden-html-snapshot');

it("captures standard-pages-password's golden HTML", function (): void {
    goldenHtmlCapturesStandardPages('password', '/password.php');
})->group('golden-html-snapshot');

it("captures standard-pages-profile's golden HTML", function (): void {
    // profile.php is auth-required, so it needs a logged-in user rather
    // than the anonymous guest row goldenHtmlCapturesStandardPages() above
    // mutates -- fixture_admin (via goldenHtmlLoginAsAdmin(), same as
    // every other admin-* capture) is reused here rather than a second
    // dedicated user: its *gallery* theme (user_infos.theme) is a
    // different setting from the *admin* theme
    // (PreferencesService::getAdminThemePref()) every admin-* capture
    // actually depends on, so mutating it here can't affect them.
    // 'favorites'/'profile' in VisualRegressionRoutes.php DO render under
    // fixture_admin's gallery theme, though -- the restore in `finally`
    // is what keeps this safe for them.
    $adminUserId = 1;
    $previousTheme = H::userTheme($adminUserId);
    expect($previousTheme)
        ->not->toBeNull("fixture admin user (id {$adminUserId}) has no user_infos row");

    H::setUserTheme($adminUserId, 'golden_html_test');

    try {
        $cookieJar = goldenHtmlLoginAsAdmin();
        $result = goldenHtmlCurl($cookieJar, '/profile.php');
        unlink($cookieJar);
    } finally {
        H::setUserTheme($adminUserId, $previousTheme ?? 'default');
    }

    expect($result['status'])->toBe(200, "standard-pages-profile returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('standard-pages-profile', $result['body']);
})->group('golden-html-snapshot');

it("captures no-photo-yet-guest's and no-photo-yet-admin's golden HTML", function (): void {
    // Page/NoPhotoYetRenderer.php (wired into RequestBootstrap.php's own
    // per-request bootstrap, not a route of its own) shows this on *any*
    // real page once ImageRepository::countAllImages() -- a bare,
    // unconditional COUNT(*) -- is 0, except inside admin context or on
    // identification/password/popuphelp (which stay reachable on
    // purpose). It renders two different content variants depending on
    // the viewer: NoPhotoYetAdminPageContext (step 2, deactivate options)
    // for a logged-in admin browsing the *gallery* (not admin.php --
    // adminContext()->isActive() excludes that entirely), or
    // NoPhotoYetGuestPageContext (step 1, a login link) for a guest.
    // Both captured from the same plain gallery-home route
    // (index.php), once anonymously and once via
    // goldenHtmlLoginAsAdmin()'s session.
    //
    // The fixture's 5 real images are load-bearing for dozens of other
    // already-captured baselines, so this can't just DELETE FROM images
    // and move on -- H::snapshotAllImages()/restoreAllImages() capture
    // and replay everything the delete touches (images itself plus its
    // real FK dependents, both the ON DELETE CASCADE tables and the 2 ON
    // DELETE SET NULL columns a cascade doesn't hand back), same
    // snapshot-then-restore shape as this file's own
    // snapshotDerivativeConfig()/restoreDerivativeConfig(), restored in
    // `finally` regardless of outcome.
    //
    // RequestBootstrap.php's own call site
    // (`if (self::currentConfig()->noPhotoYet === null)`) is the real
    // trap here: NoPhotoYetRenderer only runs at all while `no_photo_yet`
    // has never been written to `config` -- the very first request this
    // fixture ever served (with all 5 real photos present) already took
    // the `else` branch (`confUpdateParam('no_photo_yet', 'false')`), so
    // deleting images alone permanently gets ignored: confirmed live that
    // a real committed DELETE FROM images, curl'd immediately after,
    // still rendered the normal gallery page -- not stale cached data,
    // the whole check is gated shut once that config row exists.
    // H::setConfigValue('no_photo_yet', null) clears it back to "genuine
    // absence" (CurrentConfig::$noPhotoYet's own docblock) so the check
    // runs fresh, same finally-guaranteed restore discipline as the image
    // snapshot.
    $snapshot = H::snapshotAllImages();
    $previousNoPhotoYetConfig = H::configValue('no_photo_yet');

    try {
        H::deleteAllImages();
        H::setConfigValue('no_photo_yet', null);

        $guestResult = goldenHtmlCurl('', '/index.php');
        expect($guestResult['status'])->toBe(200, "no-photo-yet-guest returned HTTP {$guestResult['status']}, expected 200");

        $cookieJar = goldenHtmlLoginAsAdmin();
        $adminResult = goldenHtmlCurl($cookieJar, '/index.php');
        unlink($cookieJar);
        expect($adminResult['status'])->toBe(200, "no-photo-yet-admin returned HTTP {$adminResult['status']}, expected 200");
    } finally {
        H::restoreAllImages($snapshot);
        H::setConfigValue('no_photo_yet', $previousNoPhotoYetConfig);
    }

    goldenHtmlAssertOrWrite('no-photo-yet-guest', $guestResult['body']);
    goldenHtmlAssertOrWrite('no-photo-yet-admin', $adminResult['body']);
})->group('golden-html-snapshot');

it("captures install's golden HTML", function (): void {
    // InstallWizard::render()'s own "already installed" gate
    // (file_exists($paths->siteLocal . Env::testModeInstalledStamp()))
    // reads the exact same flag file RequestBootstrap::bootEntryPoint()
    // checks on every other route -- moving it out of the way here lets
    // install.php's real step-1 welcome/language form render instead of
    // its "Piwigo is already installed" fatalError() page. Safe for a
    // plain GET: $this->step only becomes 2 (the real schema-migration +
    // config.sql seeding path) on an explicit step-2 POST this test never
    // sends, so the fixture DB itself is never touched -- confirmed by
    // reading InstallWizard.php directly, not assumed.
    $flagPath = dirname(__DIR__, 2) . '/local/.installed.test';
    $backupPath = $flagPath . '.golden-html-backup';
    if (! rename($flagPath, $backupPath)) {
        throw new RuntimeException("Couldn't move {$flagPath} out of the way");
    }

    try {
        $result = goldenHtmlCurl('', '/install.php');
    } finally {
        if (! rename($backupPath, $flagPath)) {
            throw new RuntimeException("Couldn't restore {$flagPath} -- every other route now thinks Piwigo isn't installed");
        }
    }

    expect($result['status'])->toBe(200, "install returned HTTP {$result['status']}, expected 200");

    goldenHtmlAssertOrWrite('install', $result['body']);
})->group('golden-html-snapshot');
