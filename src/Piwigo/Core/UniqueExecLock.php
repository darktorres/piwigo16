<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: DB-row-backed "only one execution at a time" lock,
 * relocated from include/functions.inc.php -- no natural existing class
 * home, stateless beyond the config-table row it reads/writes through.
 *
 * Legacy Coupling Retirement: DI+DBAL migration Phase 1e -- retargeted
 * onto `DbConnection` (same L1Infrastructure layer, constructed inline
 * per static method, no instance state). The `INSERT IGNORE` race --
 * concurrent callers racing to insert the same unique `param` key, only
 * one winning, both re-`SELECT`ing to see whose `exec_id` persisted --
 * is the real mechanism this class exists for; every query keeps its
 * exact original SQL text (a pure execution-API swap), not rewritten
 * through QueryBuilder or newly parameterized.
 *
 * Query-extraction pass (raw-DBAL-out-of-non-Repository-classes): the raw
 * `INSERT IGNORE`/`SELECT value`/`SELECT COUNT(*)` calls now live on
 * ConfigRepository ({@see \Piwigo\Config\ConfigRepository::insertIgnoreRawValue()}/
 * {@see \Piwigo\Config\ConfigRepository::findRawValue()}/
 * {@see \Piwigo\Config\ConfigRepository::countByParam()}), reached through
 * `CurrentConfigService::get()` -- same L1Infrastructure layer (Core ->
 * Config), same exact SQL text, still deliberately bypassing the ORM
 * entity for the reasons those 3 methods document.
 *
 * Phase 5 (ConfigDb retarget sweep) found ends()'s confDeleteParam() call
 * reachable (the hard way, via a live 500) through
 * `Bootstrap\RequestBootstrap::connect()` ->
 * `Bootstrap\UserBootstrap::initialize()` ->
 * `Users\UserService::getUserData()` -> `begins()`'s own timeout branch
 * calling `ends()` -- pre-`Kernel::boot()` at the time, on every single
 * request. Legacy Coupling Retirement Phase 8, 8d: that's no longer true
 * -- `RequestBootstrap::configure()` now calls `Kernel::boot()` as its own
 * first statement (Phase 8a), well before `connect()` (and everything it
 * calls, including this chain) ever runs, and `connect()` itself now
 * resolves and `CurrentConfigService::set()`s a real `ConfigService`
 * early, before `UserBootstrap::initialize()` -- so `ends()` now goes
 * through `CurrentConfigService::get()` (Tier 2) like every other
 * retargeted call in this batch.
 */
final class UniqueExecLock
{
    public static function begins(string $tokenName, int $timeout = 60): false|string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        $configService = \Piwigo\Config\CurrentConfigService::get();

        $exec_id = substr(sha1(random_bytes(1000)), 0, 8);
        $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] starts now');

        // Row keyed by $tokenName (one per lock name, including per-user-ID
        // dynamic tokens like UserService's 'generate_user_cache-u{id}') --
        // an unbounded key space, read straight from the config table
        // rather than through a CurrentConfig property.
        $running_token = $configService->findRawValue($tokenName . '_running');
        if ($running_token !== false) {
            [$running_exec_id, $running_exec_start_time] = explode('-', $running_token);
            if (time() - (int) $running_exec_start_time > $timeout) {
                $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] exec=' . $running_exec_id . ', timeout stopped by another call to the function');
                self::ends($tokenName);
            }
        }

        $configService->insertIgnoreRawValue($tokenName . '_running', $exec_id . '-' . time());

        $running_exec = $configService->findRawValue($tokenName . '_running');
        assert(is_string($running_exec));
        [$running_exec_id] = explode('-', $running_exec);

        if ($running_exec_id !== $exec_id) {
            $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] skip');
            return false;
        }
        $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] wins the race and gets the token!');

        return $exec_id;
    }

    public static function isRunning(string $tokenName): bool
    {
        return \Piwigo\Config\CurrentConfigService::get()->countByParam($tokenName . '_running') > 0;
    }

    public static function ends(string $tokenName): void
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        \Piwigo\Config\CurrentConfigService::get()->confDeleteParam($tokenName . '_running');
        $logger->info('[' . $tokenName . '] ends now');
    }
}
