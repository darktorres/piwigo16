<?php

declare(strict_types=1);

use Piwigo\Core\ContainerDetector;

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
 */
test('detect returns [\'none\', null] in this real, non-containerized Linux environment', function (): void {
    expect(ini_get('open_basedir'))->toBeFalsy();
    expect(strtoupper(substr(PHP_OS, 0, 5)))->toBe('LINUX');
    expect(file_exists('/proc/2/sched'))->toBeTrue();
    expect(str_starts_with((string) file_get_contents('/proc/2/sched'), 'kthreadd'))->toBeTrue();

    expect(ContainerDetector::detect())->toBe(['none', null]);
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
    expect(json_decode((string) $stdout, true))->toBe(['none', null]);
});
