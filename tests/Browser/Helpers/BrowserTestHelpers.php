<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser\Helpers;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\GuessLocator;
use PHPUnit\Framework\ExpectationFailedException;
use Piwigo\Cache\CachePools;
use ReflectionMethod;
use ReflectionProperty;

/**
 * pest-plugin-browser resolves a visited/interacted-with page to one of
 * several internal concrete classes depending on call site — there's no
 * shared interface, only a documented @mixin relationship — so helpers
 * that accept "a page" use this union rather than guessing which one.
 */

/**
 * Shared helpers for Pest browser tests against the live Apache-served app.
 * Static, taking the test instance ($this from an it()/test() closure, which
 * has visit() via pest-plugin-browser's Browsable trait) as the first
 * argument — Pest's directory-scoped uses()->in() trait mixing didn't
 * expose methods to closure-bound tests reliably, so composition over a
 * plain PHP class sidesteps that entirely.
 *
 * Every visit carries the X-Piwigo-Env: test header via Playwright's
 * `extraHTTPHeaders` context option (passed through visit()'s $options to
 * Playwright's newContext() call) so the runtime reads .env.test — see
 * include/env.inc.php. Navigating within the SAME page object (->navigate(),
 * ->click(), form submits) keeps the session cookie; calling visit() again
 * starts a fresh browser context and loses it.
 */
final class BrowserTestHelpers
{
    public const string ADMIN_USER = 'fixture_admin';

    public const string ADMIN_PASS = 'fixture_admin';

    /**
     * Patterns that indicate a server-side problem rendered into the
     * response body. Mirrors what 16.x-v2's strict-assertions.ts checked —
     * Notice/Deprecated/Stack trace/Throwable are as diagnostic as a fatal.
     *
     * @return array<string, non-empty-string>
     */
    private static function serverErrorPatterns(): array
    {
        return [
            'Fatal error'       => '/Fatal error/i',
            'Parse error'       => '/Parse error/i',
            'Warning:'          => '/\bWarning:\s/',
            'Notice:'           => '/\bNotice:\s/',
            'Deprecated:'       => '/\bDeprecated:\s/',
            'Strict Standards:' => '/\bStrict Standards:\s/',
            'Stack trace:'      => '/Stack trace:/',
            'Uncaught'          => '/\bUncaught\s/',
        ];
    }

    public static function baseUrl(): string
    {
        $url = getenv('PIWIGO_BASE_URL');

        return $url !== false ? rtrim($url, '/') : '';
    }

    /** @return array<string, mixed> */
    public static function testModeOptions(): array
    {
        $headers = [
            ['name' => 'X-Piwigo-Env', 'value' => 'test'],
        ];
        if (getenv('PIWIGO_COVERAGE') === '1') {
            $headers[] = ['name' => 'X-Piwigo-Coverage', 'value' => '1'];
        }

        return [
            'extraHTTPHeaders' => $headers,
        ];
    }

    /**
     * Same conditional coverage-header logic as testModeOptions(), for the
     * raw-curl requests in this suite that don't go through Playwright's
     * visit() (and so never see testModeOptions() at all) -- matches
     * Piwigo\Tests\Integration\IntegrationTestCase::testHeader()'s own
     * CURLOPT_HTTPHEADER-shaped return. Without this, a raw-curl test's
     * real, passing coverage is invisible to composer test:coverage:all --
     * confirmed live for RegisterController/TestErrorsController/
     * VitalsController/CustomLogoController, all of which showed 0% despite
     * real tests existing, purely because their curl calls never sent this
     * header.
     *
     * @return list<string>
     */
    public static function testHeaders(): array
    {
        $headers = ['X-Piwigo-Env: test'];
        if (getenv('PIWIGO_COVERAGE') === '1') {
            $headers[] = 'X-Piwigo-Coverage: 1';
        }

        return $headers;
    }

    /**
     * Visits a path against the configured base URL, in test mode.
     *
     * $test is the Pest test case ($this from inside a test() closure).
     * Pest mixes pest-plugin-browser's Browsable trait (visit(), etc.)
     * into its generated test case class at runtime via its own plugin
     * loader (Plugin::uses(Browsable::class) in the plugin's Autoload.php)
     * — there is no interface or base class PHPStan can see this
     * through, and this project doesn't wire in Pest's own PHPStan
     * extension (which understands its plugin system). Confirmed by
     * grepping every real call site of visit()/visitPwg() in this repo:
     * $test is always $this from directly inside a Pest test() closure.
     */
    public static function visitPwg(object $test, string $path): Webpage|PendingAwaitablePage|AwaitableWebpage
    {
        // @phpstan-ignore method.notFound
        $result = $test->visit(self::baseUrl() . $path, self::testModeOptions());

        if (
            !$result instanceof Webpage
            && !$result instanceof PendingAwaitablePage
            && !$result instanceof AwaitableWebpage
        ) {
            throw new ExpectationFailedException(
                'visit() did not return a Webpage/PendingAwaitablePage/AwaitableWebpage — '
                . 'pest-plugin-browser may have changed its return type.'
            );
        }

        return $result;
    }

    /**
     * Fails if the rendered HTML contains a PHP error/warning/notice marker.
     * Call after every navigation or form submission that should produce a
     * normal page — this catches server-side breakage that JS-error
     * assertions (assertNoJavaScriptErrors()) can't see.
     */
    public static function assertNoServerErrors(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $context = ''): void
    {
        // content() is a one-shot read, not a pollable condition — same
        // reasoning as rawWebpage(), and the same fix. Confirmed needed, not
        // just theoretical: this exact call is where the photo editor page
        // (heavier DOM than plain listing pages) kept failing with "Timeout
        // 5000ms exceeded" even after navigate() was fixed.
        //
        // A second, distinct race lives here too (task #426): Playwright's
        // real server-side "page is navigating and changing the content"
        // error can fire from content() even when goto()'s own "waitUntil:
        // load" RPC call already returned -- confirmed live (git-stash A/B,
        // 5 isolated reruns, ~3/5 fail rate) that explicitly waiting on the
        // page for 'load'/'networkidle' after the preceding click() doesn't
        // prevent it, and the Apache access log shows only one real POST per
        // click (no double-submit from AwaitableWebpage's own click()
        // retry-wrap) -- so this is a genuine client/engine-level lag
        // between the frame's navigation-committed bookkeeping and the
        // network response, not a bug in this test's own call sequence.
        // Unlike retrying navigate()/click() (which redo a real mutating
        // action), content() is a pure read with no side effects, so a
        // short bounded retry scoped to exactly this one Playwright error
        // message is safe and idempotent, not a blind catch-all.
        $html = '';
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $html = self::rawWebpage($page)->content();
                break;
            } catch (ExpectationFailedException $e) {
                if (! str_contains($e->getMessage(), 'page is navigating and changing the content') || $attempt === 5) {
                    throw $e;
                }
                usleep(200_000);
            }
        }
        $hits = [];
        foreach (self::serverErrorPatterns() as $name => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                $hits[] = $name;
            }
        }

        if ($hits === []) {
            return;
        }

        $prefix = $context !== '' ? "[{$context}] " : '';
        throw new ExpectationFailedException(
            $prefix . 'Server error markers in response body: ' . implode(', ', $hits)
        );
    }

    /** Visits a path and asserts the response has no server-error markers. */
    public static function gotoOk(object $test, string $path): Webpage|PendingAwaitablePage|AwaitableWebpage
    {
        $page = self::visitPwg($test, $path);
        self::assertNoServerErrors($page, $path);

        return $page;
    }

    /**
     * Navigates an ALREADY-authenticated page to another path (keeping the
     * session cookie — unlike gotoOk()/visitPwg(), which start a fresh
     * browser context) and asserts no server-error markers.
     */
    public static function navigateOk(
        Webpage|PendingAwaitablePage|AwaitableWebpage $page,
        string $path
    ): Webpage|PendingAwaitablePage|AwaitableWebpage {
        self::rawWebpage($page)->navigate(self::baseUrl() . $path);
        self::assertNoServerErrors($page, $path);

        return $page;
    }

    /**
     * Extracts the underlying Webpage, bypassing pest-plugin-browser's
     * assertion-retry wrapper. Public: any one-shot action slower than the
     * ~1s-per-attempt ceiling described below needs this -- currently
     * navigate() (via navigateOk()) and content() (via
     * assertNoServerErrors()). InstallTest.php's click('install') (also a
     * ~4-5s server-side operation) was tried against rawWebpage()->click()
     * too, but that still hit Playwright's own ~5s default action timeout;
     * it now goes through clickWithTimeout() instead (see that method's own
     * docblock), which reaches nativePage() directly rather than
     * rawWebpage().
     *
     * AwaitableWebpage::__call() wraps every method call (except a
     * hardcoded 2-item exclusion list) in Execution::waitForExpectation(),
     * which retries the WHOLE call in a loop where each attempt gets a
     * hardcoded 1-second native Playwright timeout
     * (vendor/pestphp/pest-plugin-browser/src/Execution.php:140,
     * Playwright::usingTimeout(1_000, ...) — a literal 1_000, never derived
     * from the overall configured timeout). That's fine for polling
     * assertions (e.g. "wait for this element to become visible"), but
     * navigate() and content() are one-shot operations, not conditions to
     * poll: retrying navigate() restarts the ENTIRE page load from scratch;
     * retrying content() just re-fetches the same already-loaded DOM again
     * — neither "waits longer," both just redo work that won't finish any
     * faster the second time.
     *
     * This app's admin pages consistently take ~2-2.3s to fully load (5
     * web-font requests + combined CSS/JS bundles) — confirmed via a real
     * DEBUG=pw:api Playwright trace showing the same URL navigated to 2-3
     * times in rapid succession (~0.85-1s apart) before a test failed with
     * "Timeout {overall configured value}ms exceeded". Since every attempt
     * is capped below the ~2s the page actually needs, every attempt is
     * destined to fail and restart — confirmed empirically too: raising the
     * overall timeout 5000ms -> 15000ms made failures worse, not better,
     * since it just buys more doomed 1-second attempts. The photo editor
     * page (heavier DOM than plain listing pages) hit the same ceiling on
     * its content() call even after navigate() was fixed, confirming this
     * isn't unique to navigation specifically — it's any one-shot operation
     * wrapped in retry-until-true semantics. This is a real gap in
     * pest-plugin-browser (no public API excludes a method from the
     * retry-wrap), not an app performance problem.
     *
     * pest-plugin-browser's own first navigation of any test
     * (PendingAwaitablePage::buildAwaitablePage()) already does a raw,
     * unwrapped goto() for exactly this reason. AwaitableWebpage holds its
     * Page in a private property with no public accessor, so this extracts
     * it via reflection and returns a plain Webpage (no retry logic of its
     * own — see Api/Concerns/InteractsWithToolbar.php) wrapping the SAME
     * underlying Page, reaching the same already-existing unwrapped code
     * path pest-plugin-browser's own internals use rather than inventing
     * new behavior. That Page is mutated in place by goto(), so the
     * original AwaitableWebpage remains valid and reflects any changes
     * (e.g. a new URL) afterward.
     *
     * A PendingAwaitablePage (the type visitPwg()/gotoOk() actually return,
     * before any method has been called on it) needs one extra step: its
     * own __call() lazily builds an AwaitableWebpage on first access and
     * forwards to it — so a plain instanceof AwaitableWebpage check misses
     * it, and calling a method on it still hits the exact same retry-wrap
     * one level down. Confirmed needed, not theoretical: this exact gap is
     * why loginAsAdmin()'s very first assertNoServerErrors() call (on a
     * still-unresolved PendingAwaitablePage fresh from visitPwg()) kept
     * failing even after the AwaitableWebpage case was fixed. Force that
     * resolution via reflection too (mirroring what __call() itself would
     * do), caching the result back onto the same property so a later real
     * call on the original $page reuses it rather than building another.
     */
    public static function rawWebpage(Webpage|PendingAwaitablePage|AwaitableWebpage $page): Webpage
    {
        if ($page instanceof Webpage) {
            return $page;
        }

        return new Webpage(self::nativePage($page), '');
    }

    /**
     * Extracts the native Pest\Browser\Playwright\Page underlying any of
     * the 3 page wrapper types, via the same reflection technique
     * rawWebpage() uses -- shared so clickWithTimeout() below (which needs
     * a raw Locator, not a Webpage) doesn't duplicate the union-unwrapping
     * logic.
     */
    // @phpstan-ignore return.internalClass
    private static function nativePage(Webpage|PendingAwaitablePage|AwaitableWebpage $page): Page
    {
        if ($page instanceof Webpage) {
            $property = new ReflectionProperty(Webpage::class, 'page');

            // @phpstan-ignore instanceof.internalClass
            if (!($rawPage = $property->getValue($page)) instanceof Page) {
                throw new ExpectationFailedException(
                    'Could not extract the underlying Page from Webpage — '
                    . 'pest-plugin-browser may have renamed/retyped its internal property.'
                );
            }

            return $rawPage;
        }

        if ($page instanceof PendingAwaitablePage) {
            $pendingProperty = new ReflectionProperty(PendingAwaitablePage::class, 'waitablePage');
            $waitablePage = $pendingProperty->getValue($page);

            if (!$waitablePage instanceof AwaitableWebpage) {
                $createMethod = new ReflectionMethod(PendingAwaitablePage::class, 'createAwaitablePage');
                $waitablePage = $createMethod->invoke($page);

                if (!$waitablePage instanceof AwaitableWebpage) {
                    throw new ExpectationFailedException(
                        'PendingAwaitablePage::createAwaitablePage() did not return an AwaitableWebpage — '
                        . 'pest-plugin-browser may have changed its internal implementation.'
                    );
                }

                $pendingProperty->setValue($page, $waitablePage);
            }

            $page = $waitablePage;
        }

        $property = new ReflectionProperty(AwaitableWebpage::class, 'page');
        $rawPage = $property->getValue($page);

        // This whole helper exists to reach into pest-plugin-browser's
        // internals via reflection (see the surrounding comments); checking
        // against its internal Page type is the point, not a mistake.
        // @phpstan-ignore instanceof.internalClass
        if (!$rawPage instanceof Page) {
            throw new ExpectationFailedException(
                'Could not extract the underlying Page from AwaitableWebpage — '
                . 'pest-plugin-browser may have renamed/retyped its internal property.'
            );
        }

        return $rawPage;
    }

    /**
     * Clicks an element with an explicit Playwright-native timeout, for the
     * rare action whose server-side work genuinely exceeds even the raw
     * (non-retried) default click timeout -- see InstallTest.php's use for
     * submitting the install form (~4-5s server-side: schema creation +
     * config seeding + admin user creation). rawWebpage()->click() alone
     * isn't enough here: Webpage::click() (Api/Concerns/
     * InteractsWithElements.php) calls the locator's click() with no
     * options, so it still hits Playwright's own default action timeout
     * (~5s) -- confirmed live, it failed the same way rawWebpage() bypassing
     * pest-plugin-browser's separate ~1s-per-attempt retry-wrap already
     * fixed. GuessLocator (Pest\Browser\Support, used internally by
     * Webpage::guessLocator() -- same class, just without a public
     * pass-through for click() options) is used directly here to reach the
     * one native API (Locator::click(array $options)) that actually accepts
     * a custom timeout.
     */
    public static function clickWithTimeout(
        Webpage|PendingAwaitablePage|AwaitableWebpage $page,
        string $text,
        int $timeoutMs = 30_000
    ): void {
        // @phpstan-ignore new.internalClass
        (new GuessLocator(self::nativePage($page)))
            ->for($text)
            ->click(['timeout' => $timeoutMs]);
    }

    /**
     * Logs in as fixture_admin via the real identification.php form and
     * returns the post-login page. Asserts the logout link is present,
     * proving the session is actually authenticated (not just redirected).
     */
    // PHPStan infers fill()/click() always resolve through Webpage's
    // InteractsWithElements trait (returning self: Webpage) and claims this
    // method can never return AwaitableWebpage/PendingAwaitablePage — but
    // those two classes proxy fill()/click() through __call() (undeclared,
    // untyped to static analysis) and really do come back out here at
    // runtime: a real browser run threw "Return value must be of type
    // Webpage, AwaitableWebpage returned" after this return type was once
    // narrowed to plain Webpage, proving the union is load-bearing, not dead.
    // @phpstan-ignore return.unusedType, return.unusedType
    public static function loginAsAdmin(object $test): Webpage|PendingAwaitablePage|AwaitableWebpage
    {
        $page = self::visitPwg($test, '/identification.php');
        self::assertNoServerErrors($page, 'identification page');

        $page = $page
            ->fill('username', self::ADMIN_USER)
            ->fill('password', self::ADMIN_PASS)
            ->click('login');

        self::assertNoServerErrors($page, 'post-login landing page');
        $page->assertPresent('a[href*="act=logout"]');

        return $page;
    }

    /**
     * Calls a WS API method through the SAME authenticated browser session,
     * via a same-origin fetch() POST executed in the page (script() awaits
     * the returned promise). POST, not ->navigate() with a GET query string,
     * because several WS methods (e.g. pwg.images.setInfo) explicitly
     * reject GET with a 405 — matching how a real API client behaves.
     *
     * @param  array<string, int|string>  $params
     * @return array<string, mixed>
     */
    public static function wsCall(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $method, array $params = []): array
    {
        $body = http_build_query(array_merge(['method' => $method], $params));
        $url = self::baseUrl() . '/ws.php?format=json';
        $js = <<<JS
        fetch('{$url}', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '{$body}'
        }).then(r => r.text())
        JS;

        $result = $page->script($js);
        if (!is_string($result)) {
            throw new ExpectationFailedException(
                "WS call to {$method} did not return a string result: " . var_export($result, true)
            );
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            throw new ExpectationFailedException(
                "WS call to {$method} did not return valid JSON: " . var_export($result, true)
            );
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * Polls in-browser (via script(), which awaits the returned promise —
     * see wsCall()) until $selector is absent or hidden, instead of racing a
     * single check against an async request. Neither assertSee() nor
     * assertMissing() retry (both are one-shot checks under the hood —
     * confirmed by reading their implementations after both flaked on
     * admin-history's async-loaded search results panel).
     */
    public static function waitUntilHidden(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $selector, float $timeoutSeconds = 5.0): void
    {
        $timeoutMs = (int) ($timeoutSeconds * 1000.0);
        $js = <<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const el = document.querySelector('{$selector}');
                if (el === null || el.offsetParent === null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Timed out waiting for {$selector} to hide'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS;

        $page->script($js);
    }

    /**
     * Same polling shape as waitUntilHidden(), but for <img> elements
     * finishing to load rather than a selector disappearing -- lazily
     * generated derivative thumbnails (i.php) race
     * assertScreenshotMatches() the same way an async content panel does,
     * just via the browser's own image-loading pipeline instead of an
     * explicit ajax call.
     *
     * Two categories of <img> are deliberately excluded, not waited on:
     * an empty/unset `src` (self-references the current page URL, always
     * "complete" with naturalWidth 0 -- not a real image), and anything
     * pointing at upstream.example.invalid (the "what's new" preview
     * images, PHPWG_DOMAIN's own deliberately-unresolvable .invalid TLD --
     * see common.inc.php's own comment -- these never load by design in
     * this fork, real or test).
     */
    public static function waitUntilImagesLoaded(Webpage|PendingAwaitablePage|AwaitableWebpage $page, float $timeoutSeconds = 5.0): void
    {
        $timeoutMs = (int) ($timeoutSeconds * 1000.0);
        $js = <<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const relevant = () => Array.from(document.querySelectorAll('img'))
                .filter((img) => img.getAttribute('src') && !img.src.includes('upstream.example.invalid'));
            const check = () => {
                const imgs = relevant();
                const allLoaded = imgs.every((img) => img.complete && img.naturalWidth > 0);
                if (allLoaded) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    const pending = imgs.filter((img) => !(img.complete && img.naturalWidth > 0))
                        .map((img) => img.src + ' (complete=' + img.complete + ', naturalWidth=' + img.naturalWidth + ')');
                    return reject(new Error('Timed out waiting for images to load: ' + pending.join(', ')));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS;

        $page->script($js);
    }

    /**
     * Deletes every row from piwigo_history before a visual-regression
     * screenshot of admin.php?page=history. Its "Search" tab always filters
     * to today's date server-side (admin/history.php has no start/end GET
     * override), so it shows whatever real guest page-views the rest of
     * this very test run already logged — a different set of timestamped
     * rows every run. There's no way to pin that content via a URL param,
     * so it's frozen the same way freezeImageHits() freezes the hit
     * counter: mutate the narrow slice of DB state a screenshot depends on,
     * right before taking it, rather than exclude the page or widen tolerance.
     */
    public static function truncateHistory(): void
    {
        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $db->query(sprintf('DELETE FROM %shistory', $prefix));
        $db->close();
    }

    /** Returns the pwg_token for the current session (must be logged in). */
    public static function pwgToken(Webpage|PendingAwaitablePage|AwaitableWebpage $page): string
    {
        $status = self::wsCall($page, 'pwg.session.getStatus');

        $result = $status['result'] ?? null;
        if (!is_array($result)) {
            return '';
        }

        $token = $result['pwg_token'] ?? '';

        return is_string($token) ? $token : '';
    }

    /**
     * Pins piwigo_images.hit to a fixed value before a visual-regression
     * screenshot. Viewing a photo (picture.php, the admin photo editor)
     * increments this counter as a side effect of the very navigation the
     * screenshot needs, so without this every VR run would drift the
     * rendered "Visited N times" text by however many times each image was
     * viewed since the baseline — freezing it here, not excluding the page
     * or widening the diff tolerance.
     */
    public static function freezeImageHits(int $imageId, int $value): void
    {
        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $db->query(sprintf('UPDATE %simages SET hit = %d WHERE id = %d', $prefix, $value, $imageId));
        $db->close();
    }

    /**
     * Flips a category's status and clears the effective-permission cache
     * pools accordingly -- the same 2-step real permission recomputation a
     * real "make this album private" admin action performs (via
     * Cache\PermissionCacheInvalidator::invalidate(), gap-closure Stage 4g),
     * not just the categories.status flag alone.
     *
     * Gap-closure Stage 4g gap-closure (2026-07-25): previously wrote
     * directly to `user_cache.forbidden_categories` -- confirmed live while
     * originally adding this helper that flipping status alone left every
     * existing derivative still served to anonymous requests, because
     * Permission\ImageVisibilityChecker::isVisibleToUser() read that
     * precomputed column, never live category status. Stage 4g retargeted
     * that class onto `CurrentUser::forbiddenCategories`
     * (Permission\EffectiveForbiddenCategoriesCache, cached in
     * Cache\CachePools::permissions()/effectivePermissions()), so this
     * helper now clears those pools directly instead -- a real Browser-test
     * failure (this exact test, `2 failed`) caught the old write becoming a
     * silent no-op once `getUserData()` stopped writing `user_cache` at
     * all. Uses the app's own FilesystemAdapter-backed cache directory (no
     * ext-apcu in this environment, confirmed via CacheFactory's own
     * docblock), so a clear() from this separate CLI process is visible to
     * the real dev-server process on the next request.
     */
    public static function setCategoryPrivate(int $categoryId, bool $private): void
    {
        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $status = $private ? 'private' : 'public';
        $db->query(sprintf("UPDATE %scategories SET status = '%s' WHERE id = %d", $prefix, $status, $categoryId));
        $db->close();

        \Piwigo\Cache\CachePools::permissions()->clear();
        \Piwigo\Cache\CachePools::effectivePermissions()->clear();
    }

    /**
     * The stable, date-based directory portion of an image's stored path is
     * predictable (Env::now()'s frozen test clock -- see
     * RegenerateFixtureTest's own comment), but the filename's random
     * content-hash suffix is not: it's freshly generated every time the
     * fixture is regenerated. Callers needing a real derivative URL for a
     * specific fixture image (e.g. `i.php?/{path}-sq.jpg`) must look the
     * current path up rather than hardcode it.
     */
    public static function imagePath(int $imageId): string
    {
        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $result = $db->query(sprintf('SELECT path FROM %simages WHERE id = %d', $prefix, $imageId));
        if (! $result instanceof \mysqli_result) {
            $db->close();
            throw new \RuntimeException("imagePath(): query failed for image {$imageId}");
        }
        $row = $result->fetch_assoc();
        $db->close();
        $path = is_array($row) ? ($row['path'] ?? null) : null;
        if (! is_string($path)) {
            throw new \RuntimeException("imagePath(): no path found for image {$imageId}");
        }

        return $path;
    }

    /**
     * Sets the anonymous guest user's (user_id 2) active theme. The
     * fixture defaults it to 'default' (AppInfo::DEFAULT_TEMPLATE), so the
     * standard_pages theme's own identification/register/password/profile
     * templates are never actually exercised by a plain guest visit as
     * fixtured. Setting this to 'standard_pages' directly is the minimal,
     * restorable fixture mutation needed to exercise those templates for
     * real (confirmed live: an anonymous identification.php request only
     * renders id="piwigo-logo"/"logo-section" once this is set).
     */
    public static function setGuestTheme(string $theme): void
    {
        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $db->query(sprintf(
            "UPDATE %suser_infos SET theme = '%s' WHERE user_id = 2",
            $prefix,
            $db->real_escape_string($theme)
        ));
        $db->close();
    }

    /**
     * Configures a custom standard_pages logo end to end: writes the real
     * file onto the 'local' disk (same absolute filesystem the live
     * Apache-served app and this test process share -- a local dev
     * environment, confirmed by every other direct-DB-fixture helper in
     * this class making the same assumption) and sets the 3 config keys
     * Piwigo\Admin\ThemesStandardPagesPageRenderer's own upload form would
     * write, without driving that form's JS/file-input UI (not a plupload
     * widget like the photo uploader, but there's no need to automate it
     * either way -- this is a direct-DB-fixture-manipulation test, the
     * same class of shortcut setCategoryPrivate()/freezeImageHits() already
     * use). $relativePath is relative to the 'local' disk root (e.g.
     * 'logo/test.png') -- must match Piwigo\Controller\
     * CustomLogoController's own StorageRegistry::disk('local') resolution.
     */
    public static function setCustomLogo(string $relativePath, string $binaryContent): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $absPath = $repoRoot . '/local/' . $relativePath;
        if (! is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0777, true);
        }
        file_put_contents($absPath, $binaryContent);

        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        // use_standard_pages already defaults to 'true' in the fixture --
        // set explicitly anyway so this helper is correct standalone.
        // json_encode() each value per its own CurrentConfig property type
        // (bool stays a bare true/false literal, the two string-typed
        // paths get JSON-quoted) -- this write bypasses ConfigService::
        // encode() entirely, so it has to match that convention by hand.
        foreach ([
            'use_standard_pages' => true,
            'standard_pages_selected_logo' => 'custom_logo',
            'standard_pages_selected_logo_path' => $relativePath,
        ] as $param => $value) {
            $jsonValue = json_encode($value);
            if ($jsonValue === false) {
                throw new ExpectationFailedException("json_encode failed for config param '{$param}'");
            }

            $escaped = $db->real_escape_string($jsonValue);
            $db->query(sprintf(
                "INSERT INTO %sconfig (param, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = '%s'",
                $prefix,
                $param,
                $escaped,
                $escaped
            ));
        }
        $db->close();

        // This DB write bypasses ConfigService entirely, so the live
        // Apache-served app's own CachePools::config() (filesystem-backed
        // in this environment, real cross-process storage, not a
        // per-process optimization) would otherwise keep serving whatever
        // config it cached before this write -- same gap
        // IntegrationTestCase::loadFixture()/setUp() close for the
        // PHPUnit/Pest process, but that class isn't in play for Browser
        // tests, which hit the real server over HTTP.
        CachePools::config()->clear();
    }

    /** Reverts setCustomLogo() -- deletes the file and the 2 config keys it set (leaves use_standard_pages, already true by default). */
    public static function clearCustomLogo(string $relativePath): void
    {
        $repoRoot = dirname(__DIR__, 3);
        @unlink($repoRoot . '/local/' . $relativePath);

        $db = new \mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $db->query(sprintf(
            "DELETE FROM %sconfig WHERE param IN ('standard_pages_selected_logo', 'standard_pages_selected_logo_path')",
            $prefix
        ));
        $db->close();

        CachePools::config()->clear();
    }

    /** Generates a tiny solid-color PNG (via GD), returned as raw binary content -- for CustomLogoController tests. */
    public static function makeTestPng(): string
    {
        $img = imagecreatetruecolor(16, 16);
        if ($img === false) {
            throw new ExpectationFailedException('imagecreatetruecolor failed');
        }

        $color = imagecolorallocate($img, 10, 20, 30);
        if ($color === false) {
            throw new ExpectationFailedException('imagecolorallocate failed');
        }
        imagefill($img, 0, 0, $color);

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /**
     * Generates a small solid-color JPEG (via GD) for upload tests. Caller
     * is responsible for unlink()-ing the returned path.
     */
    public static function makeTestImage(string $label = 'Test Photo'): string
    {
        $img = imagecreatetruecolor(200, 150);
        if ($img === false) {
            throw new ExpectationFailedException('imagecreatetruecolor failed');
        }

        $bg = imagecolorallocate($img, 90, 130, 200);
        if ($bg === false) {
            throw new ExpectationFailedException('imagecolorallocate failed');
        }

        imagefill($img, 0, 0, $bg);

        $fg = imagecolorallocate($img, 255, 255, 255);
        if ($fg === false) {
            throw new ExpectationFailedException('imagecolorallocate failed');
        }

        imagestring($img, 5, 30, 70, $label, $fg);

        $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_browser_');
        if ($tmpPath === false) {
            throw new ExpectationFailedException('tempnam failed');
        }
        $path = $tmpPath . '.jpg';
        imagejpeg($img, $path, 80);

        return $path;
    }

    /**
     * Uploads a photo via the WS API using a fresh admin login over curl.
     * pest-plugin-browser has no cookie-jar access to reuse the browser
     * session for a multipart POST, and Piwigo's admin upload UI is a JS
     * (plupload) widget with no plain <input type="file"> fallback to
     * automate reliably — this mirrors what 16.x-v2's own E2E suite did
     * for the same reason (direct API upload, not simulated drag-drop).
     */
    public static function uploadPhotoViaApi(string $imagePath, int $albumId, string $name): int
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_cookies_');
        if ($cookieJar === false) {
            throw new ExpectationFailedException('tempnam failed');
        }

        self::curlWs($cookieJar, [
            'method'   => 'pwg.session.login',
            'username' => self::ADMIN_USER,
            'password' => self::ADMIN_PASS,
        ]);

        $body = self::curlWs($cookieJar, [
            'method'   => 'pwg.images.addSimple',
            'category' => (string) $albumId,
            'name'     => $name,
            'image'    => new \CURLFile($imagePath, 'image/jpeg', basename($imagePath)),
        ]);

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['stat'] ?? null) !== 'ok') {
            @unlink($cookieJar);
            throw new ExpectationFailedException('Photo upload failed: ' . var_export($body, true));
        }

        // Empties the upload "lounge" so the just-uploaded photo is visible
        // immediately, reusing the same authenticated cookie jar.
        self::curlWs($cookieJar, ['method' => 'pwg.images.emptyLounge']);
        @unlink($cookieJar);

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            throw new ExpectationFailedException('Photo upload response missing result: ' . var_export($body, true));
        }

        $imageId = $result['image_id'] ?? null;
        if (!is_numeric($imageId)) {
            throw new ExpectationFailedException('Photo upload response missing image_id: ' . var_export($body, true));
        }

        return (int) $imageId;
    }

    /**
     * Plain anonymous GET (no cookie jar) against a path relative to
     * baseUrl() -- follows redirects (e.g. i.php's own "derivative
     * identical to source" 301 to action.php) and returns the *final*
     * HTTP status code, matching what a real browser experiences
     * end-to-end. For asserting i.php/action.php-style permission-check
     * responses, where the interesting signal is 200 vs 403/404, not page
     * content.
     */
    public static function httpStatus(string $path): int
    {
        $ch = curl_init(self::baseUrl() . '/' . ltrim($path, '/'));
        if ($ch === false) {
            throw new ExpectationFailedException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $status;
    }

    /**
     * Plain anonymous GET, returning the raw response body -- for
     * assertions against rendered markup that don't need a real browser
     * (e.g. confirming an <img> tag's src attribute), matching
     * httpStatus()'s own curl shape.
     */
    public static function httpBody(string $path): string
    {
        $ch = curl_init(self::baseUrl() . '/' . ltrim($path, '/'));
        if ($ch === false) {
            throw new ExpectationFailedException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        $body = curl_exec($ch);

        return is_string($body) ? $body : '';
    }

    /** @param array<string, mixed> $fields */
    private static function curlWs(string $cookieJar, array $fields): string
    {
        // the only caller passes tempnam()'s result, always a real path
        assert($cookieJar !== '');
        $ch = curl_init(self::baseUrl() . '/ws.php?format=json');
        if ($ch === false) {
            throw new ExpectationFailedException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::testHeaders());
        $body = curl_exec($ch);
        unset($ch);

        return is_string($body) ? $body : '';
    }
}
