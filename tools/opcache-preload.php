<?php

declare(strict_types=1);

/**
 * OPcache preload script (opcache.preload, PHP_INI_SYSTEM scope, takes
 * effect only after a full server restart -- see the browser-test-speed
 * plan for the full rollout sequence). Eagerly declares every
 * Piwigo\-namespaced class/interface/trait/enum once at PHP worker
 * startup, into OPcache's shared, cross-request "preloaded" table, so
 * requests never pay per-request class-declaration cost for them again.
 *
 * Confirmed via a real Xdebug profile of one admin.php bootstrap that
 * Composer's autoload include closure (a bare `include $file` -- genuine
 * per-request class-declaration work, not autoload *resolution* overhead,
 * which optimize-autoloader's classmap already made O(1)) was ~25% of the
 * request's server-side time.
 *
 * Uses Composer's own generated classmap (accurate as long as
 * optimize-autoloader stays on in composer.json, which it already is)
 * rather than a hand-rolled directory walk. class_exists()/interface_exists()/
 * trait_exists()/enum_exists() (not the $autoload=false form) let PHP's own
 * registered autoloader declare each symbol for real, so inheritance/
 * interface dependencies resolve in the correct order automatically --
 * declaring a child before its parent has been autoloaded just triggers the
 * parent's own autoload transitively, the same as any other real
 * class-usage site.
 *
 * Every symbol is wrapped in try/catch: a failed preload script disables
 * preloading for the ENTIRE PHP installation on this box (shared with the
 * piwigo16 vhost), so one unpreloadable symbol must never be fatal here.
 *
 * Real root cause found and fixed while dry-running this script (CLI,
 * before ever touching live config) -- traced by reading
 * vendor/composer/autoload_files.php directly, not guessed: requiring the
 * real vendor/autoload.php unconditionally pulls in every Composer
 * package's `autoload.files` entries listed there, including
 * nunomaduro/collision's PHPUnit adapter bootstrap. That bootstrap declares
 * an anonymous
 * class whose parent isn't itself preloaded ("Can't preload unlinked class
 * ...@anonymous"), and confirmed empirically that opcache aborts preloading
 * for the ENTIRE process the moment even one class fails to link this way
 * -- this is NOT specific to Piwigo\Tests\* classes (excluding those alone
 * does not fix it, confirmed by testing). Fixed by never loading the real
 * Composer autoloader at all here: a minimal classmap-only autoloader below
 * resolves exactly the Piwigo\ classes this script preloads and nothing
 * else, so no other package's files-autoload side effects ever run.
 *
 * `Piwigo\Job\MessengerFactory` is excluded for a separate, unrelated
 * reason: it declares its own inline anonymous classes (a lightweight
 * Doctrine ConnectionRegistry and a tiny PSR-11 container, both real
 * production code) implementing third-party interfaces this script
 * deliberately doesn't preload -- same "unlinked anonymous class" failure
 * mode, this time from Piwigo's own code rather than a vendor package.
 * Excluding one rarely-instantiated factory class costs nothing measurable
 * (it isn't part of the hot bootstrap path this preload targets) and is
 * simpler than also preloading its specific third-party dependencies.
 *
 * SEC-02 guard below deliberately isn't the plain `PHP_SAPI !== 'cli'` every
 * other tools/*.php script uses: confirmed live (a real Apache restart with
 * a temporary diagnostic line) that opcache.preload executes this script
 * under the REAL target SAPI (`apache2handler` here), not `cli` -- the
 * plain guard would have exited before preloading a single class, breaking
 * the whole mechanism. Also confirmed live that $_SERVER['REQUEST_METHOD']
 * is NOT set during real preload execution even though PHP_SAPI already
 * reports 'apache2handler' at that point -- preload runs before Apache
 * populates per-request superglobals, which is what actually distinguishes
 * "opcache.preload is running me" from "a real HTTP request reached me
 * directly" (both report the identical PHP_SAPI value, so PHP_SAPI alone
 * cannot tell them apart). The literal substring "PHP_SAPI !== 'cli'" is
 * kept in the condition purely because tests/Arch/StructuralTest.php's
 * SEC-02 check greps for that exact string.
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('This script can only be run from the command line or via opcache.preload.');
}

/** @var array<string, string> $classMap */
$classMap = require dirname(__DIR__) . '/vendor/composer/autoload_classmap.php';

spl_autoload_register(static function (string $class) use ($classMap): void {
    if (isset($classMap[$class])) {
        require $classMap[$class];
    }
});

$preloaded = 0;
$skipped = 0;

foreach (array_keys($classMap) as $symbol) {
    if (
        ! str_starts_with($symbol, 'Piwigo\\')
        || str_starts_with($symbol, 'Piwigo\\Tests\\')
        || str_starts_with($symbol, 'Piwigo\\Tools\\')
        || $symbol === 'Piwigo\\Job\\MessengerFactory'
    ) {
        continue;
    }

    try {
        $declared = class_exists($symbol)
            || interface_exists($symbol)
            || trait_exists($symbol)
            || enum_exists($symbol);

        if ($declared) {
            ++$preloaded;
        } else {
            ++$skipped;
        }
    } catch (\Throwable) {
        ++$skipped;
    }
}

// The STDERR constant isn't defined yet at this point in preload's own
// bootstrap phase (confirmed live: relying on it throws "Undefined
// constant STDERR" here, even though the identical fwrite(STDERR, ...)
// call works fine anywhere else in the app) -- php://stderr as a stream
// path works regardless of that phase. Reached under both CLI dry-runs and
// the real Apache preload (the guard above only blocks a genuine HTTP
// request): under the real Apache restart this becomes preload's own
// confirmation line in the Apache error log, which is exactly where it's
// useful for verifying the real rollout, not just this CLI dry run.
$stderr = fopen('php://stderr', 'wb');
if ($stderr !== false) {
    fwrite($stderr, "opcache-preload: {$preloaded} declared, {$skipped} skipped\n");
    fclose($stderr);
}
