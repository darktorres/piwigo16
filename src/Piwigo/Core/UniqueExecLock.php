<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Db\Tables;

/**
 * P23 batch 8d: DB-row-backed "only one execution at a time" lock,
 * relocated from include/functions.inc.php -- no natural existing class
 * home, stateless beyond the config-table row it reads/writes through bare
 * \Piwigo\Db\MysqliDb::query()/pwg_db_*() calls (functions_mysqli.inc.php, batch 8f
 * relocate-only, finding 2 -- not becoming class methods).
 *
 * `endsExec()`'s own `conf_delete_param()` call stays bare -- that
 * function is still defined in functions.inc.php (pass 2c scope), valid
 * until then.
 */
final class UniqueExecLock
{
    public static function begins(string $tokenName, int $timeout = 60): false|string
    {
        /**
         * @var array<string, mixed> $conf
         * @var Logger $logger
         */
        global $conf, $logger;

        $exec_id = substr(sha1(random_bytes(1000)), 0, 8);
        $logger->info('[' . $tokenName . '][exec=' . $exec_id . '] starts now');

        if (isset($conf[$tokenName . '_running'])) {
            $running_token = $conf[$tokenName . '_running'];
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
        \Piwigo\Db\MysqliDb::query($query);

        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT value FROM ' . Tables::config() . ' WHERE param = "' . $tokenName . '_running"'));
        assert($row !== null);
        [$running_exec] = $row;
        [$running_exec_id] = explode('-', (string) $running_exec);

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
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
        assert($row !== null);
        [$counter] = $row;

        return $counter > 0;
    }

    public static function ends(string $tokenName): void
    {
        /** @var Logger $logger */
        global $logger;

        conf_delete_param($tokenName . '_running');
        $logger->info('[' . $tokenName . '] ends now');
    }
}
