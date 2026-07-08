<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser\Helpers;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Page;
use PHPUnit\Framework\ExpectationFailedException;
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
     * @return array<string, string>
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
        return [
            'extraHTTPHeaders' => [
                ['name' => 'X-Piwigo-Env', 'value' => 'test'],
            ],
        ];
    }

    /** Visits a path against the configured base URL, in test mode. */
    public static function visitPwg(object $test, string $path): Webpage|PendingAwaitablePage|AwaitableWebpage
    {
        return $test->visit(self::baseUrl() . $path, self::testModeOptions());
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
        $html = self::rawWebpage($page)->content();
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
     * assertion-retry wrapper.
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
    private static function rawWebpage(Webpage|PendingAwaitablePage|AwaitableWebpage $page): Webpage
    {
        if ($page instanceof Webpage) {
            return $page;
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

        if (!$rawPage instanceof Page) {
            throw new ExpectationFailedException(
                'Could not extract the underlying Page from AwaitableWebpage — '
                . 'pest-plugin-browser may have renamed/retyped its internal property.'
            );
        }

        return new Webpage($rawPage, '');
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
        $decoded = json_decode((string) $result, true);
        if (!is_array($decoded)) {
            throw new ExpectationFailedException(
                "WS call to {$method} did not return valid JSON: " . var_export($result, true)
            );
        }

        return $decoded;
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

        return (string) ($status['result']['pwg_token'] ?? '');
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

        $path = tempnam(sys_get_temp_dir(), 'pwg_browser_') . '.jpg';
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

        return (int) $decoded['result']['image_id'];
    }

    /** @param array<string, mixed> $fields */
    private static function curlWs(string $cookieJar, array $fields): string
    {
        $ch = curl_init(self::baseUrl() . '/ws.php?format=json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_HTTPHEADER     => ['X-Piwigo-Env: test'],
        ]);
        $body = curl_exec($ch);
        unset($ch);

        return is_string($body) ? $body : '';
    }
}
