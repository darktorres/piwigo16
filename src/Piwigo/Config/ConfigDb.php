<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Db\Tables;
use Piwigo\Session\SessionService;

/**
 * P23 batch 8f-4: the 5 legacy config free functions
 * (include/functions.inc.php's load_conf_from_db()/conf_update_param()/
 * conf_delete_param()/conf_get_param()/pwg_is_dbconf_writeable(), file now
 * deleted) migrated verbatim as static methods -- raw MysqliDb SQL and the
 * legacy `$conf` global semantics deliberately preserved, matching this
 * batch's refactor-only rule (the 8f-2 MysqliDb precedent: "migrate to OOP
 * only, later phases modify behavior").
 *
 * Deliberately NOT folded into Piwigo\Config\ConfigService, whose
 * similarly-named instance methods are a different, non-interchangeable
 * contract: ConfigService reads/writes Config::$data via the DI-only
 * Doctrine ConfigRepository (EntityRepository -- only constructible by the
 * container, see config/container.php), takes a single param name instead
 * of a raw SQL condition, supports neither the $parser callable nor
 * objects, and never touches the legacy `$conf` global that virtually all
 * request code still reads. This class is the live `$conf`-global path;
 * every method that mutates `$conf` (loadConfFromDb/confUpdateParam's
 * $updateGlobal branch/confDeleteParam) also mirrors the change into
 * Config::$data (P24 Track A batch A4) -- previously only $conf was kept
 * current mid-request, so Config::xxx() accessors silently returned stale
 * SCHEMA-default/env values for anything read between
 * RequestBootstrap::connect()'s own loadConfFromDb() call and
 * CommonBootstrap::run()'s later ConfigService::loadConfFromDb() merge
 * (e.g. UserBootstrap::initialize()'s guest_id/apache_authentication/
 * browser_language reads). Static methods (not instance) because real
 * callers span every layer plus root entry scripts (install.php runs
 * before any DI container can exist), same shape as Piwigo\Db\MysqliDb.
 *
 * The fatal-error path uses the statically-set HtmlRenderingInterface
 * (same L1-may-not-depend-on-L3 setter pattern as FilesystemHelper/
 * MysqliDb/Lang, wired from include/common.inc.php and the install/upgrade
 * entry scripts), falling back to a plain RuntimeException when unset.
 */
final class ConfigDb
{
    private static ?HtmlRenderingInterface $htmlRenderer = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same static-setter shape as Piwigo\Core\FilesystemHelper::setHtmlRenderer(),
     * needed because this L1Infrastructure class may not depend on
     * L3Presentation's HtmlService directly (deptrac).
     */
    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    private static function fatalError(string $msg): never
    {
        if (self::$htmlRenderer !== null) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * Add configuration parameters from database to global $conf array
     *
     * @param string $condition SQL condition
     */
    public static function loadConfFromDb(string $condition = '', bool $dieOnConditionWithNoResult = true): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $query = '
SELECT param, value
 FROM ' . Tables::config() . '
 ' . (! empty($condition) ? 'WHERE ' . $condition : '') . '
;';
        $result = \Piwigo\Db\MysqliDb::query($query);

        if ((\Piwigo\Db\MysqliDb::numRows($result) == 0) and ! empty($condition) and $dieOnConditionWithNoResult) {
            self::fatalError('No configuration data');
        }

        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $val = $row['value'] ?? '';
            // If the field is true or false, the variable is transformed into a boolean value.
            if ($val == 'true') {
                $val = true;
            } elseif ($val == 'false') {
                $val = false;
            }
            // config.param is `varchar(40) NOT NULL` in the schema, but the
            // fetch helper's return type is nullable per-column; guard rather
            // than trust it blindly since it feeds an array key.
            $param = $row['param'] ?? null;
            if (! is_string($param)) {
                continue;
            }
            $conf[$param] = $val;
            Config::override($param, $val);
        }

        trigger_notify('load_conf', $condition);
    }

    /**
     * Is the config table currently writeable?
     *
     * @since 14
     */
    public static function pwgIsDbconfWriteable(): bool
    {
        [$param, $value] = ['pwg_is_dbconf_writeable_' . SessionService::get()->generateKey(12), date('c') . ' ' . SessionService::get()->generateKey(20)];

        self::confUpdateParam($param, $value);
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT value FROM ' . Tables::config() . ' WHERE param = \'' . $param . '\''));
        assert($row !== null);
        [$dbvalue] = $row;

        if ($dbvalue != $value) {
            return false;
        }

        self::confDeleteParam($param);
        return true;
    }

    /**
     * Add or update a config parameter
     *
     * @param string $param
     * @param mixed $value scalar, array, or object (arrays/objects are serialized)
     * @param bool $updateGlobal update global *$conf* variable
     * @param ?callable $parser function to apply to the value before save in database
     * (eg: serialize, json_encode) will not be applied to *$conf* if *$parser* is *true*
     */
    public static function confUpdateParam($param, $value, $updateGlobal = false, $parser = null): void
    {
        if ($parser != null) {
            $dbValue = call_user_func($parser, $value);
        } elseif (is_array($value) || is_object($value)) {
            $dbValue = addslashes(serialize($value));
        } else {
            $dbValue = \Piwigo\Db\MysqliDb::booleanToString($value);
        }

        // call_user_func() and \Piwigo\Db\MysqliDb::booleanToString() are both typed mixed in/out;
        // a custom $parser or an untouched non-scalar $value could still hand
        // back something that isn't safe to splice into SQL as-is.
        if (! is_scalar($dbValue) && $dbValue !== null) {
            $dbValue = addslashes(serialize($dbValue));
        }

        $query = '
INSERT INTO
  ' . Tables::config() . ' (param, value)
  VALUES(\'' . $param . '\', \'' . $dbValue . '\')
  ON DUPLICATE KEY UPDATE value = \'' . $dbValue . '\'
;';

        \Piwigo\Db\MysqliDb::query($query);

        if ($updateGlobal) {
            /** @var array<string, mixed> $conf */
            global $conf;
            $conf[$param] = $value;
            Config::override($param, $value);
        }
    }

    /**
     * Delete one or more config parameters
     * @since 2.6
     *
     * @param string|string[] $params
     */
    public static function confDeleteParam($params): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! is_array($params)) {
            $params = [$params];
        }
        if (empty($params)) {
            return;
        }

        $query = '
DELETE FROM ' . Tables::config() . '
  WHERE param IN(\'' . implode('\',\'', $params) . '\')
;';
        \Piwigo\Db\MysqliDb::query($query);

        foreach ($params as $param) {
            unset($conf[$param]);
            Config::delete($param);
        }
    }

    /**
     * Return a default value for a configuration parameter.
     * @since 2.8
     *
     * @param string $param the configuration value to be extracted (if it exists)
     * @param mixed $default_value the default value for the configuration value if it does not exist.
     *
     * @return mixed The configuration value if the variable exists, otherwise the default.
     */
    public static function confGetParam($param, $default_value = null)
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        return $conf[$param] ?? $default_value;
    }
}
