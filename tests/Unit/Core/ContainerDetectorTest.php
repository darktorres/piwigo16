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
 * also rules out the two remaining still-red branches, confirmed via the
 * coverage report:
 *  - The official/LinuxServer container tagfile checks (needs PID 2 to
 *    NOT be kthreadd, which nothing in a test can fake -- /proc is a
 *    real kernel-provided special file, not writable, and its content
 *    reflects the actual host process tree) -- unreachable regardless of
 *    the tagfiles' own existence.
 *  - The `else` branch (`return ['none', null]` for a non-Linux OS or a
 *    restricted open_basedir): PHP_OS is a compile-time constant
 *    (unfakeable), and while `ini_set('open_basedir', ...)` can
 *    genuinely flip `ini_get('open_basedir')` at runtime, that setting
 *    can only ever be tightened, never cleared, for the rest of this
 *    single shared PHPUnit process (confirmed live) -- exercising it
 *    would permanently restrict every later test's filesystem access,
 *    not just this one test's.
 */
test('detect returns [\'none\', null] in this real, non-containerized Linux environment', function (): void {
    expect(ini_get('open_basedir'))->toBeFalsy();
    expect(strtoupper(substr(PHP_OS, 0, 5)))->toBe('LINUX');
    expect(file_exists('/proc/2/sched'))->toBeTrue();
    expect(str_starts_with((string) file_get_contents('/proc/2/sched'), 'kthreadd'))->toBeTrue();

    expect(ContainerDetector::detect())->toBe(['none', null]);
});
