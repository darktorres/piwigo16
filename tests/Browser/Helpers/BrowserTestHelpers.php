<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser\Helpers;

use mysqli;
use PgSql\Connection;
use RuntimeException;
use mysqli_result;
use InvalidArgumentException;
use CURLFile;
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
     * 'Internal Server Error' is this app's own ExceptionHandlerMiddleware
     * output for any unhandled exception -- deliberately generic (no stack
     * trace leaked to the client), so none of the raw-PHP-error patterns
     * above ever match it. Without this entry, gotoOk()/assertNoServerErrors()
     * silently passes on a bare 500, and the test proceeds to interact with
     * form fields that don't exist on that page -- fill()/click() (wrapped
     * in pest-plugin-browser's own retry-until-true semantics, see
     * clickWithTimeout()'s docblock) then retry for the full outer timeout
     * instead of failing fast. Confirmed live: PasswordControllerTest's own
     * "rejects a reset submission whose passwords do not match" test hit
     * exactly this gap and stalled for 60+ seconds instead of failing
     * immediately.
     *
     * @return array<string, non-empty-string>
     */
    private static function serverErrorPatterns(): array
    {
        return [
            'Fatal error'            => '/Fatal error/i',
            'Parse error'            => '/Parse error/i',
            'Warning:'               => '/\bWarning:\s/',
            'Notice:'                => '/\bNotice:\s/',
            'Deprecated:'            => '/\bDeprecated:\s/',
            'Strict Standards:'      => '/\bStrict Standards:\s/',
            'Stack trace:'           => '/Stack trace:/',
            'Uncaught'               => '/\bUncaught\s/',
            'Internal Server Error'  => '/Internal Server Error/',
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
     * See phpstan.neon's own method.notFound entry for this file for why
     * PHPStan can't resolve $test->visit() here. Confirmed by grepping
     * every real call site of visit()/visitPwg() in this repo: $test is
     * always $this from directly inside a Pest test() closure.
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
     * content() is a one-shot read, not a pollable condition — same
     * reasoning as rawWebpage(), and the same fix. Confirmed needed, not
     * just theoretical: this exact call is where the photo editor page
     * (heavier DOM than plain listing pages) kept failing with "Timeout
     * 5000ms exceeded" even after navigate() was fixed.
     *
     * A second, distinct race lives here too (task #426): Playwright's
     * real server-side "page is navigating and changing the content"
     * error can fire from content() even when goto()'s own "waitUntil:
     * load" RPC call already returned -- confirmed live (git-stash A/B,
     * 5 isolated reruns, ~3/5 fail rate) that explicitly waiting on the
     * page for 'load'/'networkidle' after the preceding click() doesn't
     * prevent it, and the Apache access log shows only one real POST per
     * click (no double-submit from AwaitableWebpage's own click()
     * retry-wrap) -- so this is a genuine client/engine-level lag
     * between the frame's navigation-committed bookkeeping and the
     * network response, not a bug in this test's own call sequence.
     * Unlike retrying navigate()/click() (which redo a real mutating
     * action), content() is a pure read with no side effects, so a
     * short bounded retry scoped to exactly this one Playwright error
     * message is safe and idempotent, not a blind catch-all.
     *
     * This method exists separately because $page->assertSee()'s own
     * internal polling is subject to the exact same race but doesn't get
     * the benefit of this retry -- confirmed live: assertSee() reported a
     * failure "on the page initially with the url [.../identification.php]"
     * (the *pre*-navigation page) even though a subsequent content() call
     * on the same $page already returned the real, correct post-navigation
     * HTML. assertSeeSettled() below is the fix for that call shape.
     */
    public static function settledContent(Webpage|PendingAwaitablePage|AwaitableWebpage $page): string
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                return self::rawWebpage($page)->content();
            } catch (ExpectationFailedException $e) {
                if (! str_contains($e->getMessage(), 'page is navigating and changing the content') || $attempt === 5) {
                    throw $e;
                }
                usleep(200_000);
            }
        }

        throw new ExpectationFailedException('unreachable');
    }

    /**
     * Same intent as $page->assertSee($text), but goes through
     * settledContent() instead of Playwright's own assertSee() polling --
     * see settledContent()'s own docblock for why that polling isn't
     * reliable immediately after navigate()/click().
     */
    public static function assertSeeSettled(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $text): void
    {
        if (! str_contains(self::settledContent($page), $text)) {
            throw new ExpectationFailedException("Expected to see text [{$text}] in the settled page content, but it was not found.");
        }
    }

    /**
     * Fails if the rendered HTML contains a PHP error/warning/notice marker.
     * Call after every navigation or form submission that should produce a
     * normal page — this catches server-side breakage that JS-error
     * assertions (assertNoJavaScriptErrors()) can't see.
     */
    public static function assertNoServerErrors(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $context = ''): void
    {
        $html = self::settledContent($page);
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
     * logic. Reaching into pest-plugin-browser's @internal Page class here
     * is deliberate, not a mistake -- pest-plugin-browser ships no PHPStan
     * extension of its own, and there's no public API for this.
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
        // @phpstan-ignore new.internalClass, method.internalClass
        (new GuessLocator(self::nativePage($page)))
            ->for($text)
            ->click(['timeout' => $timeoutMs]);
    }

    /**
     * Logs in as fixture_admin via the real identification.php form and
     * returns the post-login page. Asserts the logout link is present,
     * proving the session is actually authenticated (not just redirected).
     */
    /**
     * Every direct-DB-fixture helper below goes through this shared
     * connect()/dbQuery()/dbEscape()/dbClose()/dbFetchAssoc()/dbFetchAll()
     * set instead of building its own `new \mysqli(...)`, so both
     * PIWIGO_DB_DRIVER=mysql and =pgsql work. Mirrors the identical
     * dispatch shape RegenerateFixtureTest
     * already established for its own raw INSERTs (that class's own
     * private dbQuery()/dbEscape()/dbClose() -- not reusable here directly
     * since this is a plain static class, not an IntegrationTestCase
     * subclass), extended with the fetch helpers this file's own
     * SELECT-driven methods (configValue(), imagePath(), ...) need that
     * RegenerateFixtureTest's insert-only usage never did.
     */
    public static function connect(): mysqli|Connection
    {
        $host = (string) getenv('PIWIGO_DB_HOST');
        $user = (string) getenv('PIWIGO_DB_USER');
        $password = getenv('PIWIGO_DB_PASSWORD');
        $password = $password === false ? '' : $password;
        $base = (string) getenv('PIWIGO_DB_BASE');

        if (getenv('PIWIGO_DB_DRIVER') === 'pgsql') {
            $connStringParts = ['host=' . $host, 'user=' . $user, 'dbname=' . $base];
            if ($password !== '') {
                $connStringParts[] = 'password=' . $password;
            }

            $conn = pg_connect(implode(' ', $connStringParts), PGSQL_CONNECT_FORCE_NEW);
            if ($conn === false) {
                throw new RuntimeException('BrowserTestHelpers: pg_connect failed');
            }

            return $conn;
        }

        return new mysqli($host, $user, $password, $base);
    }

    public static function dbQuery(mysqli|Connection $db, string $sql): void
    {
        if ($db instanceof mysqli) {
            $db->query($sql);
        } else {
            pg_query($db, $sql);
        }
    }

    public static function dbEscape(mysqli|Connection $db, string $value): string
    {
        return $db instanceof mysqli ? $db->real_escape_string($value) : pg_escape_string($db, $value);
    }

    public static function dbClose(mysqli|Connection $db): void
    {
        if ($db instanceof mysqli) {
            $db->close();
        } else {
            pg_close($db);
        }
    }

    /**
     * The id an immediately-preceding INSERT (on the same connection, in
     * this same request) generated for an auto-increment/identity primary
     * key column -- mysqli's own `$db->insert_id` property has no
     * Postgres equivalent (a genuinely different id-tracking model: no
     * per-connection "last insert id" concept at all). `lastval()` is the
     * real Postgres counterpart: it returns the most recently
     * `nextval()`-consumed value for ANY sequence in the CURRENT session,
     * which every SERIAL/IDENTITY-backed id column consumes automatically
     * on insert, matching mysqli's own per-connection (not global)
     * semantics closely enough for this suite's single-INSERT-then-read
     * call shape. Verified live before porting every real
     * `$db->insert_id` call site to this.
     */
    public static function dbInsertId(mysqli|Connection $db): int
    {
        if ($db instanceof mysqli) {
            return (int) $db->insert_id;
        }

        $row = self::dbFetchAssoc($db, 'SELECT lastval() AS id');

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    /**
     * Normalizes a raw value read back from a genuine boolean column
     * (comments.validated, categories.visible, user_infos.enabled_high,
     * ...) into a real PHP bool -- real bug found live: pg_fetch_assoc()
     * represents a boolean column as the single-character string 't'/'f'
     * (confirmed live, not mysqli's own '1'/'0' or native 1/0), and BOTH
     * `(bool) $value` and `(int) $value` are silently wrong for that
     * string shape ('f' is a non-empty, non-"0" string -- (bool) 'f' is
     * true; (int) 'f' is 0, useless for round-tripping a `true` value
     * back). mysqli's own possible shapes ('1'/'0' string, or 1/0 int
     * under native-type mode) are also handled explicitly rather than
     * assumed truthy/falsy, so this is correct for either driver.
     */
    public static function dbToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value === 't' || $value === '1';
        }

        return (bool) $value;
    }

    /** @return array<string, float|int|string|null>|null */
    public static function dbFetchAssoc(mysqli|Connection $db, string $sql): ?array
    {
        if ($db instanceof mysqli) {
            $result = $db->query($sql);
            if (! $result instanceof mysqli_result) {
                return null;
            }
            $row = $result->fetch_assoc();

            return is_array($row) ? $row : null;
        }

        $result = pg_query($db, $sql);
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result);

        return $row === false ? null : self::stringKeyed($row);
    }

    /** @return list<array<string, float|int|string|null>> */
    public static function dbFetchAll(mysqli|Connection $db, string $sql): array
    {
        if ($db instanceof mysqli) {
            $result = $db->query($sql);
            if (! $result instanceof mysqli_result) {
                return [];
            }
            $rows = [];
            while (is_array($row = $result->fetch_assoc())) {
                $rows[] = $row;
            }

            return $rows;
        }

        $result = pg_query($db, $sql);
        if ($result === false) {
            return [];
        }

        $rows = [];
        while (is_array($row = pg_fetch_assoc($result))) {
            $rows[] = self::stringKeyed($row);
        }

        return $rows;
    }

    /**
     * pg_fetch_assoc()'s own stub types its returned array's keys more
     * loosely than the guaranteed-string-keyed reality of a PGSQL_ASSOC
     * fetch -- rebuilt via explicit (string) cast the same way wsCall()
     * above already normalizes a json_decode()'d array's keys, rather
     * than fighting the stub with a suppression. Generic over TValue so
     * this only reshapes keys, without also widening the real value type
     * (string|null, per pg_fetch_assoc()'s own stub) the way a flat
     * `mixed` return would.
     *
     * @template TValue
     * @param array<mixed, TValue> $row
     * @return array<string, TValue>
     */
    private static function stringKeyed(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * AuthService::pwgLogin()'s IP-scoped lockout (user_failed_logins,
     * see that method's own docblock) counts *every* recent failed login
     * from a given IP, regardless of which test caused it -- every request
     * in this suite comes from the same machine/IP, so a deliberate
     * failed-login test elsewhere (e.g. IdentificationControllerTest)
     * silently poisons this IP's attempt count for whichever *unrelated*
     * test happens to log in next, once the count crosses
     * loginLockoutMaxAttempts() within the lockout window. Confirmed live:
     * a real, legitimate admin login started failing with a genuine 401
     * "Access denied" purely from this cross-test accumulation, not from
     * a credentials problem. Called from both loginAsAdmin() and
     * uploadPhotoViaApi() (which does its own separate curl-based login)
     * so every real login path in this suite is immune to it.
     */
    public static function clearLoginLockout(): void
    {
        $db = self::connect();
        self::dbQuery($db, 'DELETE FROM user_failed_logins');
        self::dbClose($db);
    }

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
        self::clearLoginLockout();
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

    /** @var list<array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool}>|null */
    private static ?array $adminSessionCookies = null;

    /**
     * Parses a curl cookie jar (Netscape format: tab-separated domain /
     * includeSubdomains / path / secure / expiry / name / value, with an
     * optional "#HttpOnly_" prefix on the domain field for httpOnly
     * cookies) into Playwright storageState's cookie shape. Whatever
     * cookie(s) the server actually set -- name, domain, path -- are read
     * straight from the file rather than assumed, so this doesn't need to
     * know Piwigo's configured session cookie name (CurrentConfig::
     * sessionName()) or cookie path (CookieService::cookiePath()) up front.
     *
     * @return list<array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool}>
     */
    private static function parseCookieJar(string $cookieJar): array
    {
        $lines = file($cookieJar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new ExpectationFailedException('Could not read cookie jar: ' . $cookieJar);
        }

        $cookies = [];
        foreach ($lines as $line) {
            $httpOnly = str_starts_with($line, '#HttpOnly_');
            if ($line === '' || ($line[0] === '#' && !$httpOnly)) {
                continue;
            }

            $fields = explode("\t", $httpOnly ? substr($line, strlen('#HttpOnly_')) : $line);
            if (count($fields) !== 7) {
                continue;
            }

            [$domain, , $path, $secure, , $name, $value] = $fields;

            $cookies[] = [
                'name'     => $name,
                'value'    => $value,
                'domain'   => $domain,
                'path'     => $path,
                'httpOnly' => $httpOnly,
                'secure'   => $secure === 'TRUE',
            ];
        }

        if ($cookies === []) {
            throw new ExpectationFailedException('No cookies found in cookie jar: ' . $cookieJar);
        }

        return $cookies;
    }

    /**
     * Logs in as fixture_admin exactly once per test process, via the same
     * curlWs()+cookie-jar mechanism uploadPhotoViaApi() already uses for
     * its own separate curl-based login -- no browser/UI interaction, no
     * repeated page load. Caches the resulting cookie(s) so every later
     * asAdmin() call reuses the SAME server-side session instead of
     * logging in again. See asAdmin()'s own docblock for why this exists.
     *
     * @return list<array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool}>
     */
    private static function adminSessionCookies(): array
    {
        if (self::$adminSessionCookies !== null) {
            return self::$adminSessionCookies;
        }

        self::clearLoginLockout();

        $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_admin_cookies_');
        if ($cookieJar === false) {
            throw new ExpectationFailedException('tempnam failed');
        }

        self::curlWs($cookieJar, [
            'method'   => 'pwg.session.login',
            'username' => self::ADMIN_USER,
            'password' => self::ADMIN_PASS,
        ]);

        self::$adminSessionCookies = self::parseCookieJar($cookieJar);
        @unlink($cookieJar);

        return self::$adminSessionCookies;
    }

    /**
     * Visits $path already authenticated as fixture_admin, without driving
     * the real UI login form -- unlike loginAsAdmin(), which stays
     * separate and unchanged for tests that specifically need to exercise
     * that form (e.g. IdentificationControllerTest's own login-flow/
     * lockout coverage).
     *
     * Most call sites don't care *how* the session got authenticated, only
     * that it already is -- loginAsAdmin() pays for two full page loads
     * (identification.php, then the post-submit redirect; this suite's own
     * admin pages consistently take ~2-2.3s to load, see rawWebpage()'s
     * docblock) plus a DB round-trip, on EVERY single call. This visits
     * $path directly with adminSessionCookies()'s cached session seeded
     * into the fresh Playwright browser context via the 'storageState'
     * option -- confirmed by reading Browsable::visit() ->
     * PendingAwaitablePage::buildAwaitablePage() -> Browser::newContext()
     * that arbitrary $options keys passed to visit() are forwarded
     * verbatim as RPC params to Playwright's real newContext call, which
     * natively accepts a 'storageState' param shaped exactly like this
     * (Playwright's own wire protocol, not a JS-SDK-only convenience). One
     * page load instead of two, zero form interaction, and the DB
     * lockout-clearing round-trip happens once per test process instead of
     * once per test.
     *
     * Still asserts the logout link is present, exactly like
     * loginAsAdmin() -- if the cookie-based auth ever silently failed, the
     * very first test using this fails loudly here instead of masking it.
     */
    public static function asAdmin(object $test, string $path = '/admin.php'): Webpage|PendingAwaitablePage|AwaitableWebpage
    {
        $options = self::testModeOptions();
        $options['storageState'] = [
            'cookies' => self::adminSessionCookies(),
            'origins' => [],
        ];

        // @phpstan-ignore method.notFound
        $result = $test->visit(self::baseUrl() . $path, $options);

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

        self::assertNoServerErrors($result, $path);
        $result->assertPresent('a[href*="act=logout"]');

        return $result;
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
     * POSTs to an arbitrary admin.php-style path through the SAME
     * authenticated browser session, via a same-origin fetch() executed in
     * the page — same rationale as wsCall(), but for admin form-submission
     * controllers whose response is a rendered HTML page, not a WS JSON
     * envelope, and where driving real DOM form fields one at a time would
     * be slow/brittle for a form with dozens of fields. `redirect: manual`
     * so a controller's own post-save redirect is reported as a 30x status
     * with an empty body instead of being silently followed and losing the
     * real status code — matching what PageState-driven redirects actually
     * do server-side.
     *
     * @param  array<string, mixed>  $fields
     * @return array{status: int, body: string}
     */
    public static function adminPost(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $path, array $fields): array
    {
        $body = http_build_query($fields);
        $url = self::baseUrl() . '/' . ltrim($path, '/');
        $js = <<<JS
        fetch('{$url}', {
            method: 'POST',
            redirect: 'manual',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '{$body}'
        }).then(async r => JSON.stringify({status: r.status, body: await r.text()}))
        JS;

        $result = $page->script($js);
        if (!is_string($result)) {
            throw new ExpectationFailedException(
                "adminPost to {$path} did not return a string result: " . var_export($result, true)
            );
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) || !is_int($decoded['status'] ?? null) || !is_string($decoded['body'] ?? null)) {
            throw new ExpectationFailedException(
                "adminPost to {$path} did not return the expected {status, body} shape: " . var_export($result, true)
            );
        }

        return ['status' => $decoded['status'], 'body' => $decoded['body']];
    }

    /**
     * Same in-browser fetch() technique as adminPost(), but for a plain
     * authenticated GET whose real HTTP status code needs asserting (e.g. a
     * CSRF-gated GET action) -- navigateOk()/gotoOk() only assert success,
     * they don't hand back the status code for a real 400/403 response.
     *
     * cache: 'no-store' matters here specifically: admin.php sends no
     * explicit Cache-Control headers of its own, so without this a repeat
     * rawGet() to the exact same URL within one browser session/test file
     * (a real pattern in this suite -- multiple tests hit the identical
     * '/admin.php?page=notification_by_mail' path) risks the browser's own
     * default HTTP cache serving a stale response instead of making a real
     * network request, undermining the whole point of asserting a fresh
     * status code.
     *
     * @return array{status: int, body: string}
     */
    public static function rawGet(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $path): array
    {
        $url = self::baseUrl() . '/' . ltrim($path, '/');
        $js = <<<JS
        fetch('{$url}', {
            method: 'GET',
            redirect: 'manual',
            cache: 'no-store',
        }).then(async r => JSON.stringify({status: r.status, body: await r.text()}))
        JS;

        $result = $page->script($js);
        if (!is_string($result)) {
            throw new ExpectationFailedException(
                "rawGet to {$path} did not return a string result: " . var_export($result, true)
            );
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) || !is_int($decoded['status'] ?? null) || !is_string($decoded['body'] ?? null)) {
            throw new ExpectationFailedException(
                "rawGet to {$path} did not return the expected {status, body} shape: " . var_export($result, true)
            );
        }

        return ['status' => $decoded['status'], 'body' => $decoded['body']];
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
     * Deletes every row from history before a visual-regression
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
        $db = self::connect();
        self::dbQuery($db, 'DELETE FROM history');
        self::dbClose($db);
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
     * Pins images.hit to a fixed value before a visual-regression
     * screenshot. Viewing a photo (picture.php, the admin photo editor)
     * increments this counter as a side effect of the very navigation the
     * screenshot needs, so without this every VR run would drift the
     * rendered "Visited N times" text by however many times each image was
     * viewed since the baseline — freezing it here, not excluding the page
     * or widening the diff tolerance.
     */
    public static function freezeImageHits(int $imageId, int $value): void
    {
        $db = self::connect();
        self::dbQuery($db, sprintf('UPDATE images SET hit = %d WHERE id = %d', $value, $imageId));
        self::dbClose($db);
    }

    /**
     * Reads a single `config`.`value` cell (the raw JSON-encoded string
     * ConfigService::confUpdateParam() stores, not the decoded value) --
     * for asserting a real DB write happened after an adminPost()-driven
     * admin form save, same rationale/mysqli shape as freezeImageHits().
     */
    public static function configValue(string $param): ?string
    {
        $db = self::connect();
        $row = self::dbFetchAssoc($db, sprintf("SELECT value FROM config WHERE param = '%s'", self::dbEscape($db, $param)));
        self::dbClose($db);

        return is_array($row) && is_string($row['value'] ?? null) ? $row['value'] : null;
    }

    /**
     * json_encode() for a value the caller knows cannot fail to encode
     * (plain scalars/arrays, no resources or invalid-UTF8 strings) --
     * throws instead of silently handing a `false` (encode failure) or
     * `""` (accidental cast of that false) into a config/DB write, which
     * would be a real, silently-wrong value rather than a type-check
     * nuisance.
     */
    public static function jsonEncode(mixed $value): string
    {
        $encoded = json_encode($value);
        if ($encoded === false) {
            throw new RuntimeException('json_encode() failed for: ' . var_export($value, true));
        }

        return $encoded;
    }

    /**
     * Writes (or clears, when $rawJsonValue is null) a single `config`.
     * `value` cell directly, and clears the config cache pool -- the
     * counterpart to configValue(), for restoring a config param an
     * adminPost()-driven test mutated back to its pre-test value. `config`
     * is shared, global state across the whole Browser suite run (unlike
     * Contract's per-test-file fixture reset), so any test that saves a
     * real admin form must snapshot + restore whatever it touches.
     */
    public static function setConfigValue(string $param, ?string $rawJsonValue): void
    {
        $db = self::connect();
        if ($rawJsonValue === null) {
            self::dbQuery($db, sprintf("UPDATE config SET value = NULL WHERE param = '%s'", self::dbEscape($db, $param)));
        } else {
            // ON DUPLICATE KEY UPDATE has no Postgres equivalent -- ON
            // CONFLICT (param) DO UPDATE SET is the real one, same as
            // ContractTestCase::upsertConfig()'s own established branch
            // (config.param is the PRIMARY KEY on both platforms).
            $upsertSql = $db instanceof mysqli
                ? "INSERT INTO config (param, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = VALUES(value)"
                : "INSERT INTO config (param, value) VALUES ('%s', '%s') ON CONFLICT (param) DO UPDATE SET value = EXCLUDED.value";
            self::dbQuery($db, sprintf(
                $upsertSql,
                self::dbEscape($db, $param),
                self::dbEscape($db, $rawJsonValue)
            ));
        }
        self::dbClose($db);
        CachePools::config()->clear();
    }

    /**
     * Captures the current value of each given config param, for restoring
     * via restoreConfig() after a test that saves a real admin form --
     * config is shared, global state across the whole Browser suite run.
     *
     * @param  list<string>  $params
     * @return array<string, ?string>
     */
    public static function snapshotConfig(array $params): array
    {
        $snapshot = [];
        foreach ($params as $param) {
            $snapshot[$param] = self::configValue($param);
        }

        return $snapshot;
    }

    /** @param array<string, ?string> $snapshot */
    public static function restoreConfig(array $snapshot): void
    {
        foreach ($snapshot as $param => $value) {
            self::setConfigValue($param, $value);
        }
    }

    /**
     * Captures every row of `derivative_settings` (0 or 1 row) and
     * `derivative_size` (one row per named size) -- the derivative-sizing
     * equivalent of snapshotConfig(), now that ImageStdParams persists to
     * these two real tables instead of the former `derivatives`/
     * `disabled_derivatives` config blobs. Same "shared, global state
     * across the whole Browser suite run" rationale as snapshotConfig()'s
     * own docblock.
     *
     * @return array{settings: ?array<string, mixed>, sizes: list<array<string, mixed>>}
     */
    public static function snapshotDerivativeConfig(): array
    {
        $db = self::connect();

        $settings = self::dbFetchAssoc($db, 'SELECT * FROM derivative_settings');
        $sizes = self::dbFetchAll($db, 'SELECT * FROM derivative_size');

        self::dbClose($db);

        return ['settings' => $settings, 'sizes' => $sizes];
    }

    /**
     * Counterpart to snapshotDerivativeConfig(): replaces every current row
     * in both tables with exactly what was snapshotted (delete-then-insert,
     * not an upsert -- a test may have added/removed whole size rows, not
     * just edited existing ones).
     *
     * @param array{settings: ?array<string, mixed>, sizes: list<array<string, mixed>>} $snapshot
     */
    public static function restoreDerivativeConfig(array $snapshot): void
    {
        $db = self::connect();

        self::dbQuery($db, 'DELETE FROM derivative_settings');
        if ($snapshot['settings'] !== null) {
            self::insertRow($db, 'derivative_settings', $snapshot['settings']);
        }

        self::dbQuery($db, 'DELETE FROM derivative_size');
        foreach ($snapshot['sizes'] as $row) {
            self::insertRow($db, 'derivative_size', $row);
        }

        self::dbClose($db);
    }

    /**
     * Upserts the single `derivative_settings` row (id=1 by convention,
     * see DerivativeSettingsEntity) -- for seeding a specific
     * quality/watermark_json/custom_json state a test needs before driving
     * the real admin form, the derivative-sizing equivalent of
     * setConfigValue().
     *
     * @param array<string, mixed> $row must not include 'id' -- always
     *   written as DerivativeSettingsEntity::SINGLETON_ID (1)
     */
    public static function setDerivativeSettingsRow(array $row): void
    {
        $db = self::connect();

        self::dbQuery($db, 'DELETE FROM derivative_settings');
        self::insertRow($db, 'derivative_settings', ['id' => 1] + $row);
        self::dbClose($db);
    }

    /**
     * Replaces every currently-*enabled* `derivative_size` row with exactly
     * the given set (upsert given rows, delete any other enabled row not
     * in the set) -- mirrors DerivativeSizeRepository::syncEnabled()'s own
     * partitioned-delete semantics (never touches disabled rows), the
     * derivative-sizing equivalent of setConfigValue() for the former
     * `derivatives` config key specifically (never `disabled_derivatives`
     * -- same scope as the original ctSetDecodedDerivatives()).
     *
     * @param list<array<string, mixed>> $rows every row must have enabled=1
     */
    public static function syncEnabledDerivativeSizes(array $rows): void
    {
        $db = self::connect();

        $names = array_map(static function (array $row) use ($db): string {
            $rowName = $row['name'] ?? null;
            $name = is_string($rowName) ? $rowName : '';
            self::dbQuery($db, sprintf('DELETE FROM derivative_size WHERE name = \'%s\'', self::dbEscape($db, $name)));
            self::insertRow($db, 'derivative_size', $row);

            return $name;
        }, $rows);

        $inClause = $names === []
            ? "'\\0'" // never matches a real name -- keeps the DELETE valid SQL when $rows is empty
            : implode(', ', array_map(static fn (string $n): string => "'" . self::dbEscape($db, $n) . "'", $names));

        self::dbQuery($db, sprintf('DELETE FROM derivative_size WHERE enabled = 1 AND name NOT IN (%s)', $inClause));

        self::dbClose($db);
    }

    /**
     * Unquoted identifiers: every real column this is ever called with
     * (derivative_settings/derivative_size, per the two callers above) is
     * already lowercase snake_case with no reserved-word collisions on
     * either platform (confirmed by reading both tables' real DDL) --
     * unquoted works identically on both, rather than branching a
     * quote-char per platform for a case that never actually needs it.
     * Backtick quoting is simply invalid syntax on Postgres, so this
     * can't stay driver-blind.
     *
     * @param array<string, mixed> $row
     */
    private static function insertRow(mysqli|Connection $db, string $table, array $row): void
    {
        $columns = array_keys($row);
        $values = array_map(static function (mixed $value) use ($db): string {
            if ($value === null) {
                return 'NULL';
            }
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('insertRow() only accepts scalar column values, got ' . get_debug_type($value));
            }

            return "'" . self::dbEscape($db, (string) $value) . "'";
        }, array_values($row));

        self::dbQuery($db, sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $values)
        ));
    }

    /**
     * Flips a category's status and clears the effective-permission cache
     * pools accordingly -- the same 2-step real permission recomputation a
     * real "make this album private" admin action performs (via
     * Cache\PermissionCacheInvalidator::invalidate()),
     * not just the categories.status flag alone.
     *
     * Permission\ImageVisibilityChecker::isVisibleToUser() reads
     * `CurrentUser::forbiddenCategories`
     * (Permission\EffectiveForbiddenCategoriesCache, cached in
     * Cache\CachePools::permissions()/effectivePermissions()), never live
     * category status directly -- so this helper clears those pools
     * directly after flipping status. Uses the app's own
     * FilesystemAdapter-backed cache directory (no ext-apcu in this
     * environment, confirmed via CacheFactory's own docblock), so a
     * clear() from this separate CLI process is visible to the real
     * dev-server process on the next request.
     */
    public static function setCategoryPrivate(int $categoryId, bool $private): void
    {
        $db = self::connect();
        $status = $private ? 'private' : 'public';
        self::dbQuery($db, sprintf("UPDATE categories SET status = '%s' WHERE id = %d", $status, $categoryId));
        self::dbClose($db);

        CachePools::permissions()->clear();
        CachePools::effectivePermissions()->clear();
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
        $db = self::connect();
        $row = self::dbFetchAssoc($db, sprintf('SELECT path FROM images WHERE id = %d', $imageId));
        self::dbClose($db);
        $path = is_array($row) ? ($row['path'] ?? null) : null;
        if (! is_string($path)) {
            throw new RuntimeException("imagePath(): no path found for image {$imageId}");
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
        $db = self::connect();
        self::dbQuery($db, sprintf("UPDATE user_infos SET theme = '%s' WHERE user_id = 2", self::dbEscape($db, $theme)));
        self::dbClose($db);
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

        // use_standard_pages already defaults to 'true' in the fixture --
        // set explicitly anyway so this helper is correct standalone.
        // Routed through setConfigValue() so the ON CONFLICT/cache-clear
        // logic exists in exactly one place -- that method already clears
        // CachePools::config() per call.
        foreach ([
            'use_standard_pages' => true,
            'standard_pages_selected_logo' => 'custom_logo',
            'standard_pages_selected_logo_path' => $relativePath,
        ] as $param => $value) {
            self::setConfigValue($param, self::jsonEncode($value));
        }
    }

    /** Reverts setCustomLogo() -- deletes the file and the 2 config keys it set (leaves use_standard_pages, already true by default). */
    public static function clearCustomLogo(string $relativePath): void
    {
        // Real file_exists() guard, not a bare @unlink() -- @ only
        // suppresses the ini display_errors output, not Pest/PHPUnit's own
        // conversion of a real E_WARNING into a test WARNING outcome
        // (confirmed live: a caller testing the "404 when the logo file
        // was never written" case still reaches this same cleanup call).
        $repoRoot = dirname(__DIR__, 3);
        $logoPath = $repoRoot . '/local/' . $relativePath;
        if (file_exists($logoPath)) {
            unlink($logoPath);
        }

        $db = self::connect();
        self::dbQuery($db, "DELETE FROM config WHERE param IN ('standard_pages_selected_logo', 'standard_pages_selected_logo_path')");
        self::dbClose($db);

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
        self::clearLoginLockout();

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
            'image'    => new CURLFile($imagePath, 'image/jpeg', basename($imagePath)),
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

    /**
     * Runs a single-row SELECT directly against the connection and returns
     * its associative row, failing loudly instead of letting a raw
     * mysqli_result|bool/array|null (or pg_fetch_assoc's own false-on-
     * empty) ambiguity leak into a caller's own narrowing. $db is typed
     * \mysqli|\PgSql\Connection (not just \mysqli) so this stays usable by
     * every real Browser test file that builds its own connection via
     * PIWIGO_DB_DRIVER-aware code rather than only this class's own
     * connect().
     *
     * @return array<string, float|int|string|null>
     */
    public static function fetchAssocOrFail(mysqli|Connection $db, string $sql): array
    {
        $row = self::dbFetchAssoc($db, $sql);
        if ($row === null) {
            throw new ExpectationFailedException('Query returned no row: ' . $sql);
        }

        return $row;
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
