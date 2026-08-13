<?php

declare(strict_types=1);

use Piwigo\Tests\Support\DbTransactionTestOverride;

uses()
    ->in('tests/Arch', 'tests/Integration', 'tests/Contract', 'tests/Browser');

/**
 * Every Unit test runs inside one real, never-committed transaction:
 * begin() opens a fresh connection and starts it before the test body runs,
 * rollback() undoes it and closes the connection after. DbConnection::build()
 * transparently returns that same connection for the whole test (see its own
 * $testOverride docblock), so this needs no cooperation from individual
 * tests/services beyond calling DbConnection::build() as they already do --
 * this is what structurally prevents one test's writes from ever becoming
 * visible to another --parallel worker's connection, the exact bug class a
 * long stretch of this session's own work found and had to hand-fix
 * file-by-file (Category/Tag/Auth) before landing this instead.
 *
 * A DDL statement (CREATE/ALTER/DROP TABLE) implicitly commits in MySQL,
 * silently ending the transaction from that statement onward -- a test that
 * genuinely needs to run DDL (there is exactly one today,
 * InstallServiceTest.php's own `executeSqlfile() creates tables and skips
 * DROP TABLE`) calls DbTransactionTestOverride::rollback() itself as its own
 * first line, ending the enclosing transaction early and falling back to
 * DbConnection::build()'s normal fresh-connection-per-call behavior for the
 * rest of that one test; the global afterEach() below then finds nothing
 * left to roll back and is a safe no-op. Simpler than a Pest group-based
 * opt-out (Pest's own uses()->group() tags tests for --group/--exclude-group
 * CLI filtering, not conditional hook execution) and self-documenting at the
 * one call site that actually needs it.
 *
 * The rollback (and connection close) below runs before gc_collect_cycles()
 * -- both live in the same uses()->in('Unit') chain, not two separate ones,
 * since Pest's own UsesCall stores hooks in one fixed-index array per call
 * and it isn't verified elsewhere in this codebase whether two separate
 * uses()->in('Unit') registrations compose or the second clobbers the
 * first; one chain sidesteps needing to find out. Doctrine's
 * EntityManager/UnitOfWork graph is reference-cyclic (confirmed live via
 * gc_status() -- 4,381 uncollected "possible roots" after just 60 bare
 * EntityManagerFactory::build() calls in a tight loop, zero automatic
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
 */
uses()
    ->beforeEach(function (): void {
        DbTransactionTestOverride::begin();
    })
    ->afterEach(function (): void {
        DbTransactionTestOverride::rollback();
        gc_collect_cycles();
    })
    ->in('Unit');

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
