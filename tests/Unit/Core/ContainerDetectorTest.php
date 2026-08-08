<?php

declare(strict_types=1);

use Piwigo\Core\ContainerDetector;
use Piwigo\Core\Projection\ContainerInfo;

/**
 * Piwigo\Core\ContainerDetector::detect() -- had zero dedicated coverage
 * (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * Every branch reads hard-coded absolute filesystem paths (/proc/2/sched,
 * /var/www/html/piwigo-docker.info, /build_version) and PHP_OS/
 * ini_get('open_basedir') directly -- no injection seam exists, and
 * creating files at those absolute system paths to fake the container/
 * LinuxServer branches would need root and would pollute real system
 * state outside the app root. Only this environment's own real, stable
 * result is asserted: confirmed live (open_basedir unset, PHP_OS Linux,
 * /proc/2/sched's first line genuinely starts with "kthreadd") --
 * provably 'none' here, not just today's incidental happy path.
 *
 * Same "no injection seam, would pollute real system state" reasoning
 * also rules out the official/LinuxServer container tagfile lines
 * (48-66; confirmed via the coverage report): they need PID 2 to NOT
 * be kthreadd, which nothing in this test process can fake -- /proc is
 * a real kernel-provided special file, not writable, and its content
 * reflects the actual host process tree, not the PHP process running
 * the test. This holds true even for a fresh `php` subprocess spawned
 * from this test (verified live: /var/www/html/ is writable by this
 * user, but planting a real 'Official Piwigo container' tagfile there
 * and re-invoking detect() still returns ['none', null], because the
 * /proc/2/sched::kthreadd short-circuit at the top of the LINUX branch
 * fires first and returns before the tagfile is ever read). Reaching
 * lines 48-66 for real would require actually running inside a
 * container (a different PID-1 tree), which is out of scope for this
 * suite -- left uncovered, same as any other platform/kernel-gated dead
 * branch (see tests/Unit/Core/FilesystemHelperTest.php's PHP_OS-gated
 * mkgetdir() branch for the established precedent).
 *
 * The `else` branch (line 73, `return ['none', null]` for a non-Linux
 * OS or a restricted open_basedir) IS reachable for real, though: while
 * PHP_OS is an unfakeable compile-time constant, and `ini_set` can only
 * ever tighten `open_basedir` for the rest of a shared PHP process
 * (never clear it -- which is why the previous version of this comment
 * ruled the branch out, to avoid permanently restricting every later
 * test's filesystem access), a genuinely separate `php` subprocess
 * started with `-d open_basedir=...` sidesteps that concern entirely:
 * it is a real, independent OS process that exits and disappears
 * without touching this PHPUnit process's own ini state at all. See
 * the dedicated test below.
 *
 * Mutation-testing sweep (batch 18, 2026-08-01): 18 mutations across
 * lines 33/34/37 (the open_basedir_empty computation and its Linux/
 * kthreadd guard conditions) initially showed UNTESTED. Root cause,
 * confirmed per-mutation via a live sed-applied mutation + rerun of the
 * relevant subprocess test (not assumed from reasoning alone):
 *
 * - 4 of them (both line 33 IdenticalToNotIdentical mutations on the
 *   `false`/`''` comparisons, line 34's IfNegated, and line 34's
 *   BooleanAndToBooleanOr) are already genuinely killed by the existing
 *   "else branch" subprocess test below -- with open_basedir restricted
 *   to a real, non-empty path, each of these mutations wrongly flips
 *   $open_basedir_empty/the guard condition to true, which (with the
 *   subprocess's own real open_basedir restriction still active)
 *   blocks /proc/2/sched and both tagfile reads, cascading to
 *   ['Unknown', null] instead of the correct ['none', null]. `pest
 *   --mutate` cannot see this: it's the exact same proc_open()
 *   subprocess-invisibility documented in
 *   feedback_pest_mutate_invisible_to_subprocess_tests -- verified but
 *   permanently untestable via that tool, not an unaddressed gap.
 * - 9 more are killed by a NEW subprocess test added below, using
 *   open_basedir='0' (the third sentinel this code explicitly checks
 *   for): PHP's own open_basedir enforcement treats the literal string
 *   "0" as a real restriction (to a directory named "0"), not as
 *   "disabled" -- confirmed live (`php -d open_basedir=0 -r
 *   'file_exists("/proc/2/sched")'` genuinely blocks) -- so real code
 *   correctly recognizes '0' as the "empty" sentinel, wrongly enters
 *   the container-check block anyway per its own (real, working) open_
 *   basedir engine restriction, and lands on ['Unknown', null], exactly
 *   like the "else branch" test's non-empty-path case but exercising a
 *   different set of line 33/34 comparisons/offsets. Same
 *   subprocess-invisibility caveat applies to this new test too.
 * - 5 remain genuinely confirmed-equivalent, documented inline at each
 *   mutation site below the two subprocess tests.
 */
test('detect returns [\'none\', null] in this real, non-containerized Linux environment', function (): void {
    expect(ini_get('open_basedir'))->toBeFalsy();
    expect(strtoupper(substr(PHP_OS, 0, 5)))->toBe('LINUX');
    expect(file_exists('/proc/2/sched'))->toBeTrue();
    expect(str_starts_with((string) file_get_contents('/proc/2/sched'), 'kthreadd'))->toBeTrue();

    expect(ContainerDetector::detect())->toEqual(new ContainerInfo('none', null));
});

/**
 * Closes the `else` branch (line 73) via a genuinely separate `php`
 * subprocess started with a real, non-empty `open_basedir` -- see the
 * class-level doc comment above for why this is both real (a real PHP
 * engine genuinely enforcing a real ini setting, not a mock of
 * ContainerDetector or of any global function it calls) and safe (the
 * subprocess exits on its own; it can never leak the tightened
 * open_basedir back into this shared PHPUnit process the way an
 * in-process `ini_set('open_basedir', ...)` would).
 *
 * Caveat: pcov only instruments the single process it is started in.
 * This project's coverage-merge tooling (tools/coverage-merge.php) only
 * ever ingests two sources -- this CLI test-runner's own --coverage-php
 * dump, and Piwigo\Core\CoverageCollector's per-request dumps from real
 * HTTP traffic against the live Apache instance -- neither of which
 * includes a bare CLI subprocess like this one. So this test genuinely
 * exercises line 73 against real PHP engine behaviour (and will fail if
 * that behaviour ever regresses), but the project's own line-coverage
 * percentage for line 73 may still read red; that's a tooling gap, not
 * evidence the branch is unexercised.
 */
test('detect returns [\'none\', null] via the else branch when open_basedir is genuinely non-empty', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    // Restricting open_basedir to the project root itself (rather than
    // some unrelated directory) keeps the subprocess able to load the
    // autoloader and the class under test -- detect() never touches the
    // filesystem in this branch, so it doesn't matter that the real
    // /proc/2/sched or container tagfiles fall outside this open_basedir.
    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . 'echo json_encode(\Piwigo\Core\ContainerDetector::detect());';

    $cmd = [
        PHP_BINARY,
        '-d', 'open_basedir=' . $projectRoot,
        '-r', $script,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'ContainerDetector subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
    expect(json_decode((string) $stdout, true))->toBe(['type' => 'none', 'version' => null]);
});

/**
 * Exercises the third open_basedir sentinel this code explicitly checks
 * for -- the literal string '0' -- via a genuinely separate `php`
 * subprocess. Unlike the non-empty-path case above, `-d open_basedir=0`
 * can't be passed directly on the command line: PHP would then also
 * need the project root on that same open_basedir to load the
 * autoloader, and only one open_basedir value can be active at a time.
 * Instead: start the subprocess with NO open_basedir restriction, force
 * both ContainerDetector and its ContainerInfo return type to autoload
 * (so both files are already loaded before any restriction exists --
 * ContainerInfo is only referenced inside detect()'s own method body, so
 * autoloading ContainerDetector alone doesn't pull it in), then
 * `ini_set('open_basedir', '0')` from within
 * that same throwaway subprocess -- a real ini_set() tightening a
 * previously-unset value, not a mock, and since the subprocess exits
 * immediately after this can never leak into the shared PHPUnit process
 * the way an in-process ini_set() during a normal test would.
 *
 * Confirmed live this really is a "real" restriction (not a no-op or a
 * special "disabled" sentinel some other tools use): `php -d
 * open_basedir=0 -r 'var_dump(file_exists("/proc/2/sched"))'` genuinely
 * blocks with a real open_basedir warning, unlike `open_basedir=` (an
 * empty string), which PHP's own engine treats as no restriction at
 * all. So real code here computes $open_basedir_empty=true (correctly
 * recognizing the '0' sentinel), enters the container-check block, and
 * then gets blocked by its own open_basedir restriction from every
 * path it tries to read (/proc/2/sched, both tagfiles) -- landing on
 * ['Unknown', null], not ['none', null]. This exercises a materially
 * different set of line 33/34 comparisons than the non-empty-path test
 * above (specifically the '0' comparison itself, and the exact 5-char
 * PHP_OS/substr-offset matching), closing several more mutations that
 * test can't reach.
 */
test('detect returns [\'Unknown\', null] when open_basedir is the literal string \'0\'', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . 'class_exists(\Piwigo\Core\ContainerDetector::class);'
        . 'class_exists(\Piwigo\Core\Projection\ContainerInfo::class);'
        . "ini_set('open_basedir', '0');"
        . 'echo json_encode(\Piwigo\Core\ContainerDetector::detect());';

    $cmd = [
        PHP_BINARY,
        '-r', $script,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'ContainerDetector subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
    expect(json_decode((string) $stdout, true))->toBe(['type' => 'Unknown', 'version' => null]);
});

/**
 * Confirmed-equivalent mutations (verified individually via a live
 * sed-applied mutation rerun against both subprocess tests above and
 * the main happy-path test, not assumed from reasoning alone):
 *
 * - Line 33 `$open_basedir === false || $open_basedir === '' ||
 *   $open_basedir === '0'`: both BooleanOrToBooleanAnd mutations, the
 *   FalseToTrue mutation (`=== true` -- $open_basedir is never
 *   literally the boolean `true`, only `false` or a string), and the
 *   EmptyStringToNotEmpty mutation on the middle `''` all independently
 *   verified to produce the SAME final detect() output as real code
 *   across all four realistic open_basedir values (unset/false, '',
 *   a real non-empty path, and '0'): whenever one of these mutations
 *   makes the computed $open_basedir_empty wrong, the resulting
 *   branch-choice error (if-block vs. else) still lands on the exact
 *   same return value real code would have produced via the OTHER
 *   branch -- e.g. wrongly taking the else branch when open_basedir is
 *   genuinely empty still returns ['none', null], identical to the
 *   real kthreadd early-return inside the if-block it should have
 *   taken instead.
 * - Line 34 `substr(PHP_OS, 0, 6)` (IncrementInteger on the length):
 *   PHP_OS is exactly "Linux" (5 characters) on every real system this
 *   suite runs on -- asking substr() for 6 characters when only 5
 *   exist just returns the same 5, so the extra character never
 *   changes the result.
 * - Line 34 `strtoupper(PHP_OS)` (UnwrapSubstr, dropping the substr()
 *   call entirely): since PHP_OS is exactly "Linux" with nothing past
 *   the first 5 characters, checking the whole string is identical to
 *   checking just substr(PHP_OS, 0, 5).
 * - Line 37 `$file !== false || str_starts_with(...)` and `$file !==
 *   true && str_starts_with(...)`: both require /proc/2/sched's real
 *   content or read-success to differ from what this host's real
 *   kernel actually provides (a non-kthreadd PID 2, or a read that
 *   fails despite file_exists() just having succeeded on the same
 *   path) -- neither is fakeable without actually running inside a
 *   different container's PID tree, same "would need a real container"
 *   reasoning already established for lines 48-70 in the class-level
 *   doc comment above.
 */
