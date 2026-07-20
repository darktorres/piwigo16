<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Db\Tables;

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
 * Phase 5 (ConfigDb retarget sweep): ends()'s confDeleteParam() call
 * deliberately stays on ConfigDb, not CurrentConfigService -- confirmed
 * (the hard way, via a live 500) reachable pre-container through
 * `Bootstrap\RequestBootstrap::connect()` ->
 * `Bootstrap\UserBootstrap::initialize()` ->
 * `Users\UserService::getUserData()` -> `begins()`'s own timeout branch
 * calling `ends()`, all of which run before `Kernel::boot()` on every
 * single request.
 */
final class UniqueExecLock
{
    public static function begins(string $tokenName, int $timeout = 60): false|string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        $conn = \Piwigo\Db\DbConnection::build();

        $exec_id = substr(sha1(random_bytes(1000)), 0, 8);
        $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] starts now');

        if (isset(\Piwigo\Config\Config::all()[$tokenName . '_running'])) {
            $running_token = \Piwigo\Config\Config::all()[$tokenName . '_running'];
            $running_token = is_scalar($running_token) ? (string) $running_token : '';
            [$running_exec_id, $running_exec_start_time] = explode('-', $running_token);
            if (time() - (int) $running_exec_start_time > $timeout) {
                $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] exec=' . $running_exec_id . ', timeout stopped by another call to the function');
                self::ends($tokenName);
            }
        }

        $query = '
INSERT IGNORE
  INTO ' . Tables::config() . '
  SET param="' . $tokenName . '_running"
    , value="' . $exec_id . '-' . time() . '"
;';
        $conn->executeStatement($query);

        $running_exec = $conn->fetchOne('SELECT value FROM ' . Tables::config() . ' WHERE param = "' . $tokenName . '_running"');
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
        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::config() . '
  WHERE param = "' . $tokenName . '_running"
;';
        $counter = \Piwigo\Db\DbConnection::build()->fetchOne($query);

        return is_numeric($counter) && (int) $counter > 0;
    }

    public static function ends(string $tokenName): void
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        \Piwigo\Config\ConfigDb::confDeleteParam($tokenName . '_running');
        $logger->info('[' . $tokenName . '] ends now');
    }
}
