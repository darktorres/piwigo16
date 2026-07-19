<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\Connection;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Session\SessionService;

/**
 * P23 batch 8f-4: the 5 legacy config free functions
 * (include/functions.inc.php's load_conf_from_db()/conf_update_param()/
 * conf_delete_param()/conf_get_param()/pwg_is_dbconf_writeable(), file now
 * deleted) migrated as static methods, the legacy `$conf` global semantics
 * preserved. DI+DBAL migration (Legacy Coupling Retirement, post-Phase 1j):
 * every DB-touching method takes an optional trailing `?Connection $conn`
 * (lazy-optional-default, matching the established high-caller-count-class
 * pattern used elsewhere -- MailService/HtmlService -- since real callers
 * span all ~63 files across the whole app; `$conn ??= DbConnection::build()`
 * keeps every existing caller working unchanged). `confUpdateParam()`'s
 * INSERT ... ON DUPLICATE KEY UPDATE and `confDeleteParam()`'s DELETE ...
 * IN(...) are now bound parameters -- the former raw string-splice was a
 * real SQL-injection surface for any caller passing user-influenced
 * $param/$value, not just an execution-API detail.
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
        if (self::$htmlRenderer instanceof \Piwigo\Core\HtmlRenderingInterface) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * Add configuration parameters from database to global $conf array
     *
     * @param string $condition SQL condition
     */
    public static function loadConfFromDb(string $condition = '', bool $dieOnConditionWithNoResult = true, ?Connection $conn = null): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $conn ??= DbConnection::build();

        $query = '
SELECT param, value
 FROM ' . Tables::config() . '
 ' . (! empty($condition) ? 'WHERE ' . $condition : '') . '
;';
        $rows = $conn->fetchAllAssociative($query);

        if (count($rows) === 0 and ! empty($condition) and $dieOnConditionWithNoResult) {
            self::fatalError('No configuration data');
        }

        foreach ($rows as $row) {
            $val = $row['value'] ?? '';
            // If the field is true or false, the variable is transformed into a boolean value.
            if (is_scalar($val) && (string) $val === 'true') {
                $val = true;
            } elseif (is_scalar($val) && (string) $val === 'false') {
                $val = false;
            }
            // config.param is `varchar(40) NOT NULL` in the schema, but a
            // mixed row value isn't trusted blindly since it feeds an array
            // key.
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
    public static function pwgIsDbconfWriteable(?Connection $conn = null): bool
    {
        $conn ??= DbConnection::build();

        [$param, $value] = ['pwg_is_dbconf_writeable_' . SessionService::get()->generateKey(12), date('c') . ' ' . SessionService::get()->generateKey(20)];

        self::confUpdateParam($param, $value, false, null, $conn);
        $row = $conn->fetchAssociative('SELECT value FROM ' . Tables::config() . ' WHERE param = :param', [
            'param' => $param,
        ]);
        $dbvalue = $row !== false && is_scalar($row['value']) ? (string) $row['value'] : null;

        if ($dbvalue !== $value) {
            return false;
        }

        self::confDeleteParam($param, $conn);
        return true;
    }

    /**
     * Add or update a config parameter
     *
     * @param mixed $value scalar, array, or object (arrays/objects are serialized)
     * @param bool $updateGlobal update global *$conf* variable
     * @param ?callable $parser function to apply to the value before save in database
     * (eg: serialize, json_encode) will not be applied to *$conf* if *$parser* is *true*
     */
    public static function confUpdateParam(string $param, $value, $updateGlobal = false, $parser = null, ?Connection $conn = null): void
    {
        $conn ??= DbConnection::build();

        if ($parser != null) {
            $dbValue = call_user_func($parser, $value);
        } elseif (is_array($value) || is_object($value)) {
            $dbValue = addslashes(serialize($value));
        } else {
            $dbValue = SqlDialect::booleanToString($value);
        }

        // call_user_func() and SqlDialect::booleanToString() are both typed
        // mixed in/out; a custom $parser or an untouched non-scalar $value
        // could still hand back something that isn't a bindable scalar.
        if (! is_scalar($dbValue) && $dbValue !== null) {
            $dbValue = addslashes(serialize($dbValue));
        }

        $query = '
INSERT INTO
  ' . Tables::config() . ' (param, value)
  VALUES(:param, :value)
  ON DUPLICATE KEY UPDATE value = :value2
;';

        $conn->executeStatement($query, [
            'param' => $param,
            'value' => $dbValue,
            'value2' => $dbValue,
        ]);

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
    public static function confDeleteParam($params, ?Connection $conn = null): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! is_array($params)) {
            $params = [$params];
        }
        if (empty($params)) {
            return;
        }

        $conn ??= DbConnection::build();

        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param IN (:params)')
            ->setParameter('params', $params, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeStatement();

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
    public static function confGetParam(string $param, $default_value = null)
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        return $conf[$param] ?? $default_value;
    }
}
