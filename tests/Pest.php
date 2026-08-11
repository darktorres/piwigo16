<?php

declare(strict_types=1);

uses()
    ->in('tests/Arch', 'tests/Integration', 'tests/Contract', 'tests/Browser');

/**
 * Doctrine's EntityManager/UnitOfWork graph is reference-cyclic (confirmed
 * live via gc_status() -- 4,381 uncollected "possible roots" after just 60
 * bare EntityManagerFactory::build() calls in a tight loop, zero automatic
 * collector runs), so a Connection trapped in that cycle keeps its
 * underlying Postgres/MySQL socket open until PHP's cycle collector
 * actually runs, not merely until the last reference drops. Under
 * `composer test`'s --parallel (one long-lived PHP process per worker,
 * running many test files back to back, confirmed via ParaTest's
 * WrapperRunner/WrapperWorker), those uncollected connections accumulate
 * across the whole worker's run and don't hit the runtime's automatic
 * 10,001-root threshold before Postgres's own max_connections (100) does
 * -- confirmed live via pg_stat_activity during a real run: dozens of
 * idle backends, each having already run its one query and DEALLOCATE,
 * simply never closed. Forcing a sweep after every test keeps each
 * worker's live connection count bounded to what its current test
 * actually needs, matching a plain PHP request's own per-request teardown
 * (this whole class of accumulation is structurally impossible there).
 *
 * Scoped to tests/Unit specifically (not the other suites here) via
 * uses()->afterEach()->in(...), not a bare global afterEach() -- Pest's
 * own AfterEachRepository keys hooks by the file they're *declared* in
 * (confirmed live: a bare afterEach() call in this file never fired for
 * any real test, since it registered under this file's own path, which
 * defines zero tests of its own). uses()->afterEach()->in() is the actual
 * directory-scoped mechanism.
 */
uses()
    ->afterEach(function (): void {
        gc_collect_cycles();
    })->in('Unit');

/**
 * glob() with a false-safe, reindexed return -- keeps callers' types
 * honest without short-ternary fallbacks. Lives here, not in a single
 * Arch test file, because tests/Pest.php is the one file Pest always
 * loads in every worker process -- under --parallel, ParaTest splits
 * test FILES across separate worker processes, so a plain top-level
 * `function globPaths()` declared in one test file is only defined in
 * whichever worker that specific file happened to land in, leaving it
 * undefined ("Call to undefined function globPaths()") in any other
 * worker running a DIFFERENT file that also calls it (StructuralTest.php
 * and LegacyDirectoryTest.php both do).
 *
 * @return list<string>
 */
function globPaths(string $pattern): array
{
    $matches = glob($pattern);

    return $matches === false ? [] : $matches;
}
