<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/** base directory of plugins */
define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH . 'plugins/');

// Relocated from the deleted include/functions_calendar.inc.php (P23 batch
// 8c) -- used only by Piwigo\Calendar\CalendarRenderer/CalendarMonthly/
// CalendarWeekly, kept as global constants (not class constants) to match
// this migration's established minimal-footprint precedent for widely-
// used bootstrap-level constants (PHPWG_PLUGINS_PATH above).
/** URL keyword for list view */
define('CAL_VIEW_LIST', 'list');
/** URL keyword for calendar view */
define('CAL_VIEW_CALENDAR', 'calendar');
// Chronology-date array indexes used throughout CalendarMonthly/
// CalendarWeekly -- CWEEK and CMONTH intentionally share index 1 --
// CalendarMonthly only ever uses CYEAR/CMONTH/CDAY and CalendarWeekly only
// ever uses CYEAR/CWEEK/CDAY, never both in the same $chronology_date array.
/** level of year view */
define('CYEAR', 0);
/** level of week view in weekly view */
define('CWEEK', 1);
/** level of month view in monthly view */
define('CMONTH', 1);
/** level of day view */
define('CDAY', 2);

// Relocated from the deleted include/functions_search.inc.php (P23 batch
// 8c) -- quick-search token modifier bitmask flags, used throughout
// Piwigo\Search\* (SearchService/QMultiToken/QSingleToken/QExpression/
// QDateRangeScope/QNumericRangeScope), same minimal-footprint relocation
// precedent as the calendar constants above.
define('QST_QUOTED', 0x01);
define('QST_NOT', 0x02);
define('QST_OR', 0x04);
define('QST_WILDCARD_BEGIN', 0x08);
define('QST_WILDCARD_END', 0x10);
define('QST_WILDCARD', QST_WILDCARD_BEGIN | QST_WILDCARD_END);
define('QST_BREAK', 0x20);

include_once PHPWG_ROOT_PATH . 'include/functions_session.inc.php';
include_once PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php';

/** no option for mkgetdir() */
define('MKGETDIR_NONE', 0);
/** sets mkgetdir() recursive */
define('MKGETDIR_RECURSIVE', 1);
/** sets mkgetdir() exit script on error */
define('MKGETDIR_DIE_ON_ERROR', 2);
/** sets mkgetdir() add a index.htm file */
define('MKGETDIR_PROTECT_INDEX', 4);
/** sets mkgetdir() add a .htaccess file*/
define('MKGETDIR_PROTECT_HTACCESS', 8);
/** default options for mkgetdir() = MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX */
define('MKGETDIR_DEFAULT', MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX);

/**
 * P23 batch 8d: every real caller retargets directly to
 * Piwigo\Activity\ActivityService::record() (the 3 L2aCoreDomain callers --
 * UserService/GroupService/AuthService -- via constructor-injected
 * Piwigo\Core\ActivityLoggerInterface, everything else straight to the
 * concrete class), except ONE deliberate exception:
 * Piwigo\Admin\Category\CategoryAdminService::setCatStatus() keeps calling
 * this bare -- its own Unit test (CategoryAdminServiceTest.php) relies on
 * same-namespace function shadowing to intercept the call as a spy, which
 * only works for a genuinely bare, unqualified call; retargeting it to a
 * real `new ActivityService(...)` construction would make that Unit test
 * hit a real (unavailable) DB connection instead. This function itself
 * stays defined here (not deleted) purely to keep that one call site
 * working -- same "one narrow, structurally-forced exception" shape as
 * fatal_error(), not a general policy.
 *
 * @param int|string|array<int, int|string> $object_id
 * @param array<string, mixed> $details
 */
function pwg_activity(string $object, $object_id, string $action, array $details = []): void
{
    new ActivityService(new ActivityRepository(DbConnection::build()))
        ->record($object, $object_id, $action, $details);
}

/**
 * returns the corresponding value from $themeconf if existing or an empty string
 *
 * P23 batch 8d: permanent free-function facade, same structural shape as
 * fatal_error()/check_pwg_token() (finding 8 case 3) -- one of its two
 * real external callers, Piwigo\Image\SrcImage (L2aCoreDomain), cannot
 * depend on Piwigo\Template\Template (L3Presentation), which this
 * function's own real logic needs (Template::get_themeconf()). Already
 * fully delegated (its own body is a pure Template::get_themeconf() call),
 * so "real logic lives in a real class" is already satisfied; only the
 * thin facade survives, deliberately, not an oversight to fix later.
 *
 * @param string $key
 */
function get_themeconf($key): string
{
    // common.inc.php always initializes $GLOBALS['template'] to a Template
    // instance before user code (including theme conf lookups) can run.
    $template = $GLOBALS['template'];
    assert($template instanceof Template);

    $value = $template->get_themeconf($key);

    return is_string($value) ? $value : '';
}

/**
 * Returns webmaster mail address depending on $conf['webmaster_id']
 *
 * P23 batch 8d: every real caller retargets directly to
 * Piwigo\Users\UserRepository::getWebmasterMailAddress(), except ONE
 * deliberate exception: Piwigo\Mail\MailService::getMailSenderEmail()/
 * mail()'s Bcc-webmaster branch keep calling this bare -- both are spied
 * on by tests/Unit/Mail/MailServiceTest.php and
 * tests/Unit/Job/SendNotificationEmailHandlerTest.php via same-namespace
 * function shadowing, which only works for a genuinely bare, unqualified
 * call; retargeting would make those Unit tests hit a real (unavailable)
 * DB connection. Same "one narrow, structurally-forced exception" shape
 * as pwg_activity()'s own CategoryAdminService::setCatStatus() exception.
 * This function itself stays defined here (not deleted) purely to keep
 * those 2 call sites working -- its own body is a pure delegate.
 */
function get_webmaster_mail_address(): string
{
    return new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())
        ->getWebmasterMailAddress();
}

/**
 * check if a theme is installed (directory exists)
 *
 * P23 batch 8d: every real caller retargets directly to
 * Piwigo\Core\ThemeCatalog::checkThemeInstalled(), except TWO deliberate
 * exceptions: Piwigo\Users\UserService::checkAndSaveUserInfos()/
 * getDefaultTheme() keep calling this bare -- both are spied on by
 * tests/Integration/ExtensionLifecycleTest.php via same-namespace function
 * shadowing (its own isolated bootstrap doesn't load \Piwigo\Db\MysqliDb::query(), which
 * getDefaultTheme()'s own get_pwg_themes()-based fallback branch would hit
 * if this check ever fell through to it for real). Same "one narrow,
 * structurally-forced exception" shape as pwg_activity()'s
 * CategoryAdminService::setCatStatus() exception. This function itself
 * stays defined here (not deleted) purely to keep those 2 call sites
 * working -- its own body is a pure delegate.
 *
 * @param string $theme_id
 */
function check_theme_installed($theme_id): bool
{
    return \Piwigo\Core\ThemeCatalog::checkThemeInstalled($theme_id);
}

// P23 batch 8d: the 5 functions below (load_conf_from_db/
// pwg_is_dbconf_writeable/conf_update_param/conf_delete_param/
// conf_get_param) are permanent free-function facades, NOT deferred --
// checked and rejected as migration candidates, not an oversight.
// Piwigo\Config\ConfigService already has equivalent, real, typed methods
// (loadConfFromDb()/pwgIsDbconfWriteable()/confUpdateParam()/
// confDeleteParam()/confGetParam(), built P13/P14, wired into
// CommonBootstrap::run() since P23 batch 1) -- but unlike every other
// class this migration retargets onto, ConfigService's own
// ConfigRepository cannot be constructed inline the
// `new XRepository(DbConnection::build())` way every other repository in
// this codebase uses: it extends Doctrine's EntityRepository, which
// requires a real EntityManager + ClassMetadata and is only ever obtained
// via `$em->getRepository(ConfigEntry::class)` inside the DI container
// (config/container.php's own comment: "EntityRepository's real
// constructor takes ClassMetadata, which PHP-DI can't autowire"). These 5
// functions have 90+ real call sites (conf_update_param alone) spanning
// dozens of files that are not themselves DI-constructed classes --
// install/db/*.php one-shot migration snippets, install.php/upgrade.php,
// admin/include/functions.php and include/ws_functions/*.php (not yet
// migrated, batches 8e/file-3), and already-migrated classes never
// designed to accept a ConfigService constructor dependency. Retargeting
// would mean constructor-injecting ConfigService into every one of those
// call sites -- a fundamentally different, much larger scope of work than
// this pass's "inline-construct and retarget" pattern, not a sub-pass.
// Deferred to whenever that's tackled deliberately (plausibly folded into
// the already-tracked post-P23 ORM migration step, which unifies the
// Doctrine-vs-DBAL split these functions straddle), not part of P23
// batch 8.
/**
 * Add configuration parameters from database to global $conf array
 *
 * @param string $condition SQL condition
 */
function load_conf_from_db($condition = '', bool $die_on_condition_with_no_result = true): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $query = '
SELECT param, value
 FROM ' . Tables::config() . '
 ' . (! empty($condition) ? 'WHERE ' . $condition : '') . '
;';
    $result = \Piwigo\Db\MysqliDb::query($query);

    if ((\Piwigo\Db\MysqliDb::numRows($result) == 0) and ! empty($condition) and $die_on_condition_with_no_result) {
        new HtmlService()
            ->fatalError('No configuration data');
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
    }

    trigger_notify('load_conf', $condition);
}

/**
 * Is the config table currentable writeable?
 *
 * @since 14
 */
function pwg_is_dbconf_writeable(): bool
{
    [$param, $value] = ['pwg_is_dbconf_writeable_' . SessionService::get()->generateKey(12), date('c') . ' ' . SessionService::get()->generateKey(20)];

    conf_update_param($param, $value);
    $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT value FROM ' . Tables::config() . ' WHERE param = \'' . $param . '\''));
    assert($row !== null);
    [$dbvalue] = $row;

    if ($dbvalue != $value) {
        return false;
    }

    conf_delete_param($param);
    return true;
}

/**
 * Add or update a config parameter
 *
 * @param string $param
 * @param mixed $value scalar, array, or object (arrays/objects are serialized)
 * @param bool $updateGlobal update global *$conf* variable
 * @param callable $parser function to apply to the value before save in database
 * (eg: serialize, json_encode) will not be applied to *$conf* if *$parser* is *true*
 */
function conf_update_param($param, $value, $updateGlobal = false, $parser = null): void
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
    }
}

/**
 * Delete one or more config parameters
 * @since 2.6
 *
 * @param string|string[] $params
 */
function conf_delete_param($params): void
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
function conf_get_param($param, $default_value = null)
{
    /** @var array<string, mixed> $conf */
    global $conf;
    return $conf[$param] ?? $default_value;
}

/**
 * check token comming from form posted or get params to prevent csrf attacks.
 * if pwg_token is empty action doesn't require token
 * else pwg_token is compare to server token
 */
function check_pwg_token(): void
{
    $result = new CsrfService()
        ->check();
    if ($result === null) {
        new HtmlService()
            ->badRequest('missing token');
    } elseif ($result === false) {
        new HtmlService()
            ->accessDenied();
    }
}

/**
 * Piwigo *anonymously* sends technical data and general statistics, such as number
 * of photos or list of plugins used. It helps piwigo.org to know better how Piwigo
 * is used. This way developers can focus on features that matter most.
 *
 * P23 batch 8d: permanent free-function facade, same structural shape as
 * fatal_error()/check_pwg_token()/get_themeconf() -- its own real logic
 * (Piwigo\Admin\PiwigoInfosSender::send()) constructs Piwigo\Admin\
 * plugins/themes (L4Integration) to cross-reference installed extensions
 * against the PEM directory, which its own caller (Piwigo\Page\
 * PageTailRenderer, L3Presentation) cannot reach directly either --
 * confirmed already documented in that exact file for the structurally
 * identical "check for updates" block. Already fully delegated (this
 * body is a pure PiwigoInfosSender::send() call), so "real logic lives in
 * a real class" is already satisfied; only the thin facade survives,
 * deliberately.
 *
 * @since 15
 */
function send_piwigo_infos(): void
{
    new \Piwigo\Admin\PiwigoInfosSender()
        ->send();
}
