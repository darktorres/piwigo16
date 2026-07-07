<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser\Helpers;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\ExpectationFailedException;

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
        $html = $page->content();
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
        $page = $page->navigate(self::baseUrl() . $path);
        self::assertNoServerErrors($page, $path);

        return $page;
    }

    /**
     * Logs in as fixture_admin via the real identification.php form and
     * returns the post-login page. Asserts the logout link is present,
     * proving the session is actually authenticated (not just redirected).
     */
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
