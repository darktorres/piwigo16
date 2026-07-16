<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Gettext\Headers;
use Gettext\Loader\PoLoader;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\plugins;
use Piwigo\Admin\themes;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Logger;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Lang\Translator;
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
include_once PHPWG_ROOT_PATH . 'include/functions_html.inc.php';
include_once PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php';

use Piwigo\Image\ImageStdParams;

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
 * Does the current user must log visits in history table
 *
 * @since 14
 *
 * @param int $image_id
 * @param string $image_type
 */
function do_log($image_id = null, $image_type = null): bool
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $do_log = $conf['log'];
    if (\Piwigo\Auth\AccessControl::isAdmin()) {
        $do_log = $conf['history_admin'];
    }
    if (\Piwigo\Auth\AccessControl::isAGuest()) {
        $do_log = $conf['history_guest'];
    }

    $do_log = trigger_change('pwg_log_allowed', $do_log, $image_id, $image_type);

    // trigger_change() hands the value through arbitrary registered event
    // handlers (mixed return); the contract of this filter is a bool, so a
    // misbehaving handler falls back to the pre-filter truthiness instead of
    // being trusted blindly.
    return is_bool($do_log) ? $do_log : (bool) $do_log;
}

/**
 * log the visit into history table
 *
 * @param int $image_id
 * @param string $image_type
 */
function pwg_log($image_id = null, $image_type = null, int|string|null $format_id = null): bool
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var array<string, mixed> $page
     */
    global $conf, $user, $page;

    $last_visit = $user['last_visit'] ?? null;
    $last_visit_str = is_string($last_visit) ? $last_visit : (is_numeric($last_visit) ? (string) $last_visit : '');
    $session_length = $conf['session_length'];
    $session_length = is_numeric($session_length) ? (int) $session_length : 0;

    $update_last_visit = false;
    if (empty($last_visit) or strtotime($last_visit_str) < time() - $session_length) {
        $update_last_visit = true;
    }
    $update_last_visit = trigger_change('pwg_log_update_last_visit', $update_last_visit);

    $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

    if ((bool) $update_last_visit) {
        $query = '
UPDATE ' . Tables::userInfos() . '
  SET last_visit = NOW(),
      lastmodified = lastmodified
  WHERE user_id = ' . $user_id . '
';
        pwg_query($query);
    }

    if (! do_log($image_id, $image_type)) {
        return false;
    }

    $page_section = $page['section'] ?? null;
    $page_section = is_string($page_section) ? $page_section : null;

    $tags_string = null;
    if ($page_section === 'tags') {
        $tag_ids = $page['tag_ids'] ?? [];
        $tag_ids = is_array($tag_ids) ? array_filter($tag_ids, is_scalar(...)) : [];
        $tags_string = implode(',', $tag_ids);

        if (strlen($tags_string) > 50) {
            // we need to truncate, mysql won't accept a too long string
            $tags_string = substr($tags_string, 0, 50);
            // the last tag_id may have been truncated itself, so we must
            // remove it — unless there's no comma at all (a single tag_id
            // >= 50 digits long, not realistic but keep the substring as-is)
            $last_comma = strrpos($tags_string, ',');
            if ($last_comma !== false) {
                $tags_string = substr($tags_string, 0, $last_comma);
            }
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $ip = is_string($ip) ? $ip : '';
    // IPv6 should not be longer than 39 chars, and that is the maximum length of
    // the column in the database, but in case it would be longer, let's truncate it.
    if (strlen($ip) > 39) {
        $ip = substr($ip, 0, 39);
    }

    // If plugin developers add their own sections, Piwigo will automatically add it in the history.section enum column
    if ($page_section !== null) {
        // set cache if not available
        if (! isset($conf['history_sections_cache'])) {
            conf_update_param('history_sections_cache', get_enums(Tables::history(), 'section'), true);
        }

        $cached_sections = $conf['history_sections_cache'];
        $cached_sections = is_string($cached_sections) || is_array($cached_sections) ? \Piwigo\Core\ArrayHelper::safeUnserialize($cached_sections) : null;
        if (! is_array($cached_sections)) {
            $cached_sections = get_enums(Tables::history(), 'section');
        }

        $history_sections_cache = [];
        foreach ($cached_sections as $cached_section) {
            if (is_string($cached_section)) {
                $history_sections_cache[] = $cached_section;
            }
        }

        $conf['history_sections_cache'] = $history_sections_cache;

        if (
            in_array($page_section, $history_sections_cache)
            or in_array(strtolower($page_section), array_map(strtolower(...), $history_sections_cache))
        ) {
            $section = $page_section;
        } elseif ((bool) preg_match('/^[a-zA-Z0-9_-]+$/', $page_section)) {
            $history_sections = get_enums(Tables::history(), 'section');
            $history_sections[] = $page_section;

            // alter history table structure, to include a new section
            pwg_query('ALTER TABLE ' . Tables::history() . ' CHANGE section section enum(\'' . implode("','", array_unique($history_sections)) . '\') DEFAULT NULL;');

            // and refresh cache
            conf_update_param('history_sections_cache', get_enums(Tables::history(), 'section'), true);

            $section = $page_section;
        }
    }

    // $user['id']/$page[...] are read from loosely-typed global bags fed by
    // DB rows (string|null) and session/config data (mixed); narrow each
    // to the scalar the column actually stores before splicing into SQL.
    $category = $page['category'] ?? null;
    $category = is_array($category) ? $category : [];
    $category_id = $category['id'] ?? null;
    $category_id = is_numeric($category_id) ? (int) $category_id : null;
    $search_id = $page['search_id'] ?? null;
    $search_id = is_numeric($search_id) ? (int) $search_id : null;
    $auth_key_id = $page['auth_key_id'] ?? null;
    $auth_key_id = is_numeric($auth_key_id) ? (int) $auth_key_id : null;

    $query = '
INSERT INTO ' . Tables::history() . '
  (
    date,
    time,
    user_id,
    IP,
    section,
    category_id,
    search_id,
    image_id,
    image_type,
    format_id,
    auth_key_id,
    tag_ids
  )
  VALUES
  (
    CURRENT_DATE,
    CURRENT_TIME,
    ' . $user_id . ',
    \'' . $ip . '\',
    ' . (isset($section) ? "'" . $section . "'" : 'NULL') . ',
    ' . ($category_id ?? 'NULL') . ',
    ' . ($search_id ?? 'NULL') . ',
    ' . ($image_id ?? 'NULL') . ',
    ' . (isset($image_type) ? "'" . $image_type . "'" : 'NULL') . ',
    ' . ($format_id ?? 'NULL') . ',
    ' . ($auth_key_id ?? 'NULL') . ',
    ' . (isset($tags_string) ? "'" . $tags_string . "'" : 'NULL') . '
  )
;';
    pwg_query($query);

    $history_id = (int) pwg_db_insert_id();
    if ($history_id % 1000 == 0) {
        new HistoryService(new HistoryRepository(DbConnection::build()))->summarize(50000);
    }

    $history_autopurge_every = $conf['history_autopurge_every'];
    $history_autopurge_every = is_numeric($history_autopurge_every) ? (int) $history_autopurge_every : 0;
    if ($history_autopurge_every > 0 and $history_id % $history_autopurge_every == 0) {
        new HistoryService(new HistoryRepository(DbConnection::build()))->autopurge();
    }

    return true;
}

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
 * Redirects to the given URL (HTTP method).
 *
 * @param string $url
 */
function redirect_http($url): never
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    // default url is on html format
    $url = html_entity_decode($url);
    header('Request-URI: ' . $url);
    header('Content-Location: ' . $url);
    header('Location: ' . $url);
    exit();
}

/**
 * Redirects to the given URL (HTML method).
 *
 * @param string $url
 * @param string $msg
 * @param int $refresh_time
 */
function redirect_html($url, $msg = '', $refresh_time = 0): never
{
    /** @var array<string, mixed> $conf */
    global $user, $template, $lang_info, $conf, $lang, $t2, $page, $debug;
    // $title/$refresh/$url_link below must reach $GLOBALS (not stay
    // function-local) -- include/page_header.php reads them via `global`,
    // and PHP's include-inside-a-function only shares the enclosing
    // function's LOCAL scope, never $GLOBALS, unless explicitly declared
    // here. A real, pre-existing bug (title stayed null for every real
    // redirect_html() caller), only surfaced live once PageHeaderRenderer
    // started requiring a real string instead of silently letting
    // strip_tags(null) fatal deeper inside the original code.
    global $title, $refresh, $url_link;

    // $template/$lang_info are genuinely not always set here: this function
    // can be called very early (e.g. a fatal before common.inc.php finishes
    // bootstrapping), which is exactly what this isset() check detects --
    // do not declare $template's type above, it would make PHPStan wrongly
    // treat this real fallback path as dead code.
    if (! isset($lang_info) || ! isset($template)) {
        $guest_id = $conf['guest_id'];
        $guest_id = is_numeric($guest_id) ? (int) $guest_id : 0;
        $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->buildUser($guest_id, true);
        load_language('common.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
            'no_fallback' => true,
            'local' => true,
        ]);
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme());
    } elseif (defined('IN_ADMIN') and IN_ADMIN) {
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme());
    }

    // Neither branch above runs when $template was already set and we're
    // not in admin -- it's the pre-existing bootstrap Template in that case,
    // but re-check for real since that isn't provable here statically.
    if (! ($template instanceof Template)) {
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme());
    }

    if (empty($msg)) {
        $msg = nl2br(l10n('Redirection...'));
    }

    $refresh = $refresh_time;
    $url_link = $url;
    $title = 'redirection';

    $template->set_filenames([
        'redirect' => 'redirect.tpl',
    ]);

    include PHPWG_ROOT_PATH . 'include/page_header.php';

    $template->set_filenames([
        'redirect' => 'redirect.tpl',
    ]);
    $template->assign('REDIRECT_MSG', $msg);

    $template->parse('redirect');

    include PHPWG_ROOT_PATH . 'include/page_tail.php';

    exit();
}

/**
 * Redirects to the given URL (automatically choose HTTP or HTML method).
 *
 * @param string $url
 * @param string $msg
 * @param int $refresh_time
 */
function redirect($url, $msg = '', $refresh_time = 0): never
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // with RefeshTime <> 0, only html must be used
    if ($conf['default_redirect_method'] == 'http'
        and $refresh_time == 0
        and ! headers_sent()
    ) {
        redirect_http($url);
    } else {
        redirect_html($url, $msg, $refresh_time);
    }
}

/**
 * translation function.
 * returns the corresponding value from _$lang_ if existing else the key is returned
 * if more than one parameter is provided sprintf is applied
 *
 * @param string $key
 * @return string
 */
function l10n($key)
{
    /**
     * @var array<string, mixed> $lang
     * @var array<string, mixed> $conf
     */
    global $lang, $conf;

    if (($val = @$lang[$key]) === null) {
        $debug_l10n = $conf['debug_l10n'] ?? false;
        if ((bool) $debug_l10n and ! isset($lang[$key]) and ! empty($key)) {
            trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
        }
        $val = $key;
    }

    // $lang[$key] is read from a loosely-typed global bag (language files
    // are plain PHP arrays with no enforced value type); the translation
    // contract is always a string, so a non-string value falls back to the
    // key itself rather than being trusted blindly.
    $val = is_string($val) ? $val : $key;

    if (func_num_args() > 1) {
        $args = array_slice(func_get_args(), 1);
        $values = [];
        foreach ($args as $arg) {
            // vsprintf() only accepts scalars/null; a caller passing
            // something else (array/object) has no sane string
            // representation here, so it degrades to an empty placeholder
            // instead of crashing the whole translated string.
            $values[] = is_scalar($arg) || $arg === null ? $arg : '';
        }
        $val = vsprintf($val, $values);
    }

    return $val;
}

/**
 * returns the printf value for strings including %d
 * returned value is concorded with decimal value (singular, plural)
 *
 * @param string $singular_key
 * @param string $plural_key
 * @param int|float|string $decimal real callers pass numeric DB-row
 *     strings here too (e.g. menubar.inc.php's $user['nb_total_images'],
 *     a raw query-result value) -- the old body's loose `>`/`==`
 *     comparisons tolerated that silently; Translator::plural() takes a
 *     strict native int, so this coerces before delegating (confirmed via
 *     a real 500: menubar_categories.tpl's compiled l10n_dec() call
 *     passed exactly such a string).
 */
function l10n_dec($singular_key, $plural_key, $decimal): string
{
    // Delegates to Translator's real ngettext()-based plural evaluation
    // (P16) -- the locale's actual Plural-Forms rule from its .po header,
    // not the old zero_plural-only heuristic (which only ever
    // distinguished "1" from "everything else", wrong for 3+-form
    // locales like Russian/Arabic).
    $n = is_numeric($decimal) ? (int) $decimal : 0;

    return Translator::get()->plural($singular_key, $plural_key, $n);
}

/**
 * returns a single element to use with l10n_args
 *
 * @param string $key translation key
 * @param mixed $args arguments to use on sprintf($key, args)
 *   if args is a array, each values are used on sprintf
 * @return array{key_args: array<int, mixed>}
 */
function get_l10n_args($key, $args = ''): array
{
    if (is_array($args)) {
        // array_values() guarantees a plain list even when $args carries
        // string keys, so the merged result stays an int-keyed list
        // matching the documented return shape (positional sprintf args).
        $key_arg = array_merge([$key], array_values($args));
    } else {
        $key_arg = [$key,  $args];
    }
    return [
        'key_args' => $key_arg,
    ];
}

/**
 * returns a string formated with l10n elements.
 * it is usefull to "prepare" a text and translate it later
 * @see get_l10n_args()
 *
 * @param mixed $key_args one l10n_args element or array of l10n_args
 *   elements; the array shape isn't enforced by a native type, so the
 *   is_array() check below is a real runtime guard against malformed input,
 *   not a redundant one
 * @param string $sep used when translated elements are concatened
 */
function l10n_args($key_args, $sep = "\n"): string
{
    $result = '';
    if (is_array($key_args)) {
        $first = true;
        foreach ($key_args as $key => $element) {
            if ($first) {
                $first = false;
            } else {
                $result .= $sep;
            }

            if ($key === 'key_args') {
                // built by get_l10n_args(): array{key_args: array<int, mixed>}
                // — 'key_args' is always an array here, but $key_args's
                // declared type is mixed, so the shape isn't provable
                // statically and needs a real runtime check.
                if (! is_array($element)) {
                    continue;
                }

                $l10n_key = array_shift($element);
                if (! is_string($l10n_key)) {
                    continue;
                }

                array_unshift($element, l10n($l10n_key)); // translate the key
                $formatted = call_user_func_array(sprintf(...), $element);
                $result .= is_string($formatted) ? $formatted : '';
            } else {
                $result .= l10n_args($element, $sep);
            }
        }
    } else {
        fatal_error('l10n_args: Invalid arguments');
    }

    return $result;
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
 * shadowing (its own isolated bootstrap doesn't load pwg_query(), which
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
    $result = pwg_query($query);

    if ((pwg_db_num_rows($result) == 0) and ! empty($condition) and $die_on_condition_with_no_result) {
        fatal_error('No configuration data');
    }

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
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
    $row = pwg_db_fetch_row(pwg_query('SELECT value FROM ' . Tables::config() . ' WHERE param = \'' . $param . '\''));
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
        $dbValue = boolean_to_string($value);
    }

    // call_user_func() and boolean_to_string() are both typed mixed in/out;
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

    pwg_query($query);

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
    pwg_query($query);

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
 * creates an simple hashmap based on a SQL query.
 * choose one to be the key, another one to be the value.
 *
 * @return array<int|string, mixed>
 */

/**
 * creates an associative array based on a SQL query.
 * choose one to be the key
 *
 * @return array<int|string, mixed>
 */

/**
 * creates a numeric array based on a SQL query.
 * if _$fieldname_ is empty the returned value will be an array of arrays
 * if _$fieldname_ is provided the returned value will be a one dimension array
 *
 * @return array<int|string, mixed>
 */

/**
 * returns the parent (fallback) language of a language.
 * if _$lang_id_ is null it applies to the current language
 * @since 2.6
 *
 * @param string $lang_id
 */
function get_parent_language($lang_id = null): ?string
{
    if (empty($lang_id)) {
        /** @var array<string, mixed> $lang_info */
        global $lang_info;
        $parent = $lang_info['parent'] ?? null;
        return (is_string($parent) && ! empty($parent)) ? $parent : null;
    } else {
        $f = PHPWG_ROOT_PATH . 'language/' . $lang_id . '/common.po';
        if (is_readable($f)) {
            $parent = (new PoLoader())->loadFile($f)
                ->getHeaders()
                ->get('X-Piwigo-Parent');
            return ($parent !== null && $parent !== '') ? $parent : null;
        }
    }
    return null;
}

/**
 * Rebuilds the legacy $lang_info array shape from a .po file's headers --
 * load_language()'s PO path uses this so callers that still read
 * $lang_info['language_name']/['country']/['direction']/['code']/
 * ['zero_plural']/['parent']/['jquery_code']/['plupload_code'] (admin
 * Smarty templates, get_parent_language()) keep working unchanged after
 * the .lang.php source files are gone -- see php-to-po-fn.php's own
 * X-Piwigo-* header list for what's preserved and why.
 *
 * @return array<string, string|bool>
 */
function po_headers_to_lang_info(Headers $headers): array
{
    $info = [];
    $map = [
        'X-Piwigo-Language-Name' => 'language_name',
        'X-Piwigo-Country' => 'country',
        'X-Piwigo-Direction' => 'direction',
        'X-Piwigo-Code' => 'code',
        'X-Piwigo-Parent' => 'parent',
        'X-Piwigo-Jquery-Code' => 'jquery_code',
        'X-Piwigo-Plupload-Code' => 'plupload_code',
    ];
    foreach ($map as $header => $key) {
        $value = $headers->get($header);
        if ($value !== null && $value !== '') {
            $info[$key] = $value;
        }
    }
    $info['zero_plural'] = $headers->get('X-Piwigo-Zero-Plural') === 'true';

    return $info;
}

/**
 * includes a language file or returns the content of a language file
 *
 * tries to load in descending order:
 *   param language, user language, default language
 *
 * @param string $filename
 * @param string $dirname
 * @param array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool} $options can contain
 *     @option string language - language to load
 *     @option bool return - if true the file content is returned
 *     @option bool no_fallback - if true do not load default language
 *     @option bool|string force_fallback - force pre-loading of another language
 *        default language if *true* or specified language
 *     @option bool local - if true load file from local directory
 */
function load_language($filename, $dirname = '', array $options = []): string|bool
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, array<string, mixed>> $language_files
     */
    global $user, $language_files;

    // keep trace of plugins loaded files for switch_lang_to() function
    if (! empty($dirname) && ! empty($filename) && ! ($options['return'] ?? false)
      && ! isset($language_files[$dirname][$filename])) {
        $language_files[$dirname][$filename] = $options;
    }

    if (! ($options['return'] ?? false)) {
        $filename .= '.php';
    }
    if (empty($dirname)) {
        $dirname = PHPWG_ROOT_PATH;
    }
    $dirname .= 'language/';

    $default_language = (defined('PHPWG_INSTALLED') and ! defined('UPGRADES_PATH')) ?
        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage() : AppInfo::DEFAULT_LANGUAGE;

    // construct list of potential languages
    // Every element pushed here must be a real string: $user['language'] and
    // $options['force_fallback'] are the only entries whose static type
    // isn't already a plain string, so both get an explicit is_string()
    // guard before joining the list (array_unique()/implode() below need
    // string-castable elements, not just an array container).
    $languages = [];
    if (! empty($options['language'])) { // explicit language
        $languages[] = $options['language'];
    }
    if (! empty($user['language']) && is_string($user['language'])) { // use language
        $languages[] = $user['language'];
    }
    if (($parent = get_parent_language()) != null) { // parent language
        // this is only for when the "child" language is missing
        $languages[] = $parent;
    }
    if (isset($options['force_fallback'])) { // fallback language
        // this is only for when the main language is missing
        if ($options['force_fallback'] === true) {
            $options['force_fallback'] = $default_language;
        }
        if (is_string($options['force_fallback'])) {
            $languages[] = $options['force_fallback'];
        }
    }
    if (! ($options['no_fallback'] ?? false)) { // default language
        $languages[] = $default_language;
    }

    $languages = array_unique($languages);

    // find first existing
    $source_file = '';
    $selected_language = '';
    foreach ($languages as $language) {
        $f = ($options['local'] ?? false) ?
          $dirname . $language . '.' . $filename :
          $dirname . $language . '/' . $filename;

        // Core language files were converted to .po in P16 -- $f is a
        // .lang.php-style path (the '.php' suffix appended above), which no
        // longer exists on disk for the ~322 converted core files (only
        // their .po sibling does now). Plugins/themes without a .po file
        // yet still ship the plain .lang.php this existence check originally
        // relied on exclusively.
        $po_sibling = preg_replace('/\.lang\.php$/', '.po', $f);

        if (file_exists($f) || ($po_sibling !== null && $po_sibling !== $f && file_exists($po_sibling))) {
            $selected_language = $language;
            $source_file = $f;
            break;
        }
    }

    if (! empty($source_file)) {
        if (! ($options['return'] ?? false)) {
            // $source_file is a .lang.php path here (see the '.php' suffix
            // appended above); its sibling .po -- core content only, P16
            // converted all 322 real ones -- takes priority. Plugins/
            // themes without a .po file yet keep working via the PHP-
            // array include path below (Translator::translate()'s own
            // $GLOBALS['lang'] fallback is what makes that safe to mix
            // with PO-loaded core strings).
            $po_file = preg_replace('/\.lang\.php$/', '.po', $source_file);

            global $lang, $lang_info;
            if (! isset($lang) || ! is_array($lang)) {
                $lang = [];
            }
            if (! isset($lang_info) || ! is_array($lang_info)) {
                $lang_info = [];
            }

            if ($po_file !== null && is_readable($po_file)) {
                $translations = Translator::get()->load($selected_language, $po_file);
                $load_lang_info = $translations !== null ? po_headers_to_lang_info($translations->getHeaders()) : [];

                if (isset($options['force_fallback']) && is_string($options['force_fallback'])
                  && $options['force_fallback'] !== $selected_language) {
                    $fallback_po = $dirname . $options['force_fallback'] . '/' . basename($po_file);
                    if (is_readable($fallback_po)) {
                        Translator::get()->load($options['force_fallback'], $fallback_po);
                    }
                }

                $parent_language = is_string($load_lang_info['parent'] ?? null) && $load_lang_info['parent'] !== ''
                    ? $load_lang_info['parent']
                    : (is_string($lang_info['parent'] ?? null) ? $lang_info['parent'] : null);

                if (! empty($parent_language) && $parent_language !== $selected_language) {
                    $parent_po = $dirname . $parent_language . '/' . basename($po_file);
                    if (is_readable($parent_po)) {
                        // Load the parent, then re-load the child (already
                        // loaded above) -- Translator::load()'s merge
                        // (gettext/translator's addTranslations() ->
                        // array_replace_recursive(), and mirrorToGlobal()'s
                        // own $GLOBALS['lang'] writes) both give precedence
                        // to whichever load() call happens last. Loading
                        // only the parent here would let it silently
                        // override the child's own strings for any key both
                        // define; re-loading the child restores the correct
                        // "child overrides parent, parent fills the gaps"
                        // precedence (e.g. en_US inherits piwigo_day_N from
                        // its en_UK parent, but keeps its own overrides).
                        Translator::get()->load($parent_language, $parent_po);
                        Translator::get()->load($selected_language, $po_file);
                    }
                }

                $lang_info = array_merge($lang_info, $load_lang_info);
                return true;
            }

            // load forced fallback
            if (isset($options['force_fallback']) && is_string($options['force_fallback'])
              && $options['force_fallback'] != $selected_language) {
                @include str_replace($selected_language, $options['force_fallback'], $source_file);
            }

            // load language content
            @include $source_file;
            // get_defined_vars() (rather than reading $lang/$lang_info
            // directly) keeps their real, include-dependent type visible to
            // static analysis instead of appearing to always be undefined.
            $included_vars = get_defined_vars();
            $load_lang = $included_vars['lang'] ?? null;
            $load_lang_info = $included_vars['lang_info'] ?? null;

            // load parent language content directly in global
            if (is_array($load_lang_info) && ! empty($load_lang_info['parent']) && is_string($load_lang_info['parent'])) {
                $parent_language = $load_lang_info['parent'];
            } elseif (! empty($lang_info['parent']) && is_string($lang_info['parent'])) {
                $parent_language = $lang_info['parent'];
            } else {
                $parent_language = null;
            }

            if (! empty($parent_language) && $parent_language != $selected_language) {
                @include str_replace($selected_language, $parent_language, $source_file);
            }

            // merge contents
            $lang = array_merge($lang, (array) $load_lang);
            $lang_info = array_merge($lang_info, (array) $load_lang_info);
            return true;
        } else {
            $content = @file_get_contents($source_file);
            // Note: target charset is always utf-8 $content = convert_charset($content, 'utf-8', $target_charset);
            return $content;
        }
    }

    return false;
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
        bad_request('missing token');
    } elseif ($result === false) {
        access_denied();
    }
}

/**
 * Piwigo *anonymously* sends technical data and general statistics, such as number
 * of photos or list of plugins used. It helps piwigo.org to know better how Piwigo
 * is used. This way developers can focus on features that matter most.
 *
 * @since 15
 */
function send_piwigo_infos(): void
{
    /**
     * @var Logger $logger
     * @var array<string, mixed> $conf
     */
    global $logger, $conf;

    $start_time = \Piwigo\Core\TimingHelper::getMoment();

    if (! (bool) $conf['send_piwigo_infos']) {
        return;
    }

    // $conf['send_piwigo_infos_last_notice'] has been loaded in include/common, maybe
    // a few seconds earlier, we need a refreshed value from the database. Another
    // concurrent execution might have already performed send_piwigo_infos 3 seconds ago.
    load_conf_from_db('param = "send_piwigo_infos_last_notice"', false);

    $do_send = false;
    $last_notice = $conf['send_piwigo_infos_last_notice'] ?? null;
    // conf_get_param()/load_conf_from_db() both feed $conf through a
    // loosely-typed mixed pipeline, but this particular param is always a
    // MySQL datetime string once set; only strtotime()'s argument needs the
    // narrowing since the isset() check above doesn't provide a type.
    $last_notice_str = is_string($last_notice) ? $last_notice : null;
    if ($last_notice_str !== null) {
        // conf_get_param() reads $conf with a dynamic (non-literal) key, so
        // its return stays mixed even though this specific param is always
        // an int of seconds; narrow it before splicing into strtotime()'s
        // relative-time string.
        $period = conf_get_param('send_piwigo_infos_period', 7 * 24 * 60 * 60);
        $period = is_numeric($period) ? (int) $period : 7 * 24 * 60 * 60;
        if (strtotime($last_notice_str) < strtotime($period . ' second ago')) {
            $do_send = true;
        }
    } else {
        $do_send = true;
    }

    if (! $do_send) {
        return;
    }

    $logger->info('[' . __FUNCTION__ . '] current conf.send_piwigo_infos_last_notice=' . ($last_notice_str ?? 'notFound') . ' => lets do it');

    if (! pwg_is_dbconf_writeable()) {
        $logger->info('[' . __FUNCTION__ . '] conf is not writeable, abort');
        return;
    }

    $exec_id = \Piwigo\Core\UniqueExecLock::begins('send_piwigo_infos');
    if ($exec_id === false) {
        $logger->info('[' . __FUNCTION__ . '] another execution is running, abort');
        return;
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $row = pwg_db_fetch_row(pwg_query('SELECT now();'));
    assert($row !== null);
    [$db_current_date] = $row;

    if (! isset($conf['send_piwigo_infos_origin_hash'])) {
        conf_update_param('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
    }

    [$container_type, $container_version] = \Piwigo\Core\ContainerDetector::detect();

    $piwigo_infos = [
        'origin_hash' => $conf['send_piwigo_infos_origin_hash'],
        'technical' => [
            'php_version' => PHP_VERSION,
            'piwigo_version' => AppInfo::VERSION,
            'os_version' => PHP_OS,
            'container_type' => $container_type,
            'container_version' => $container_version,
            'db_version' => pwg_get_db_version(),
            'php_datetime' => date('Y-m-d H:i:s'),
            'db_datetime' => $db_current_date,
            'graphics_library' => get_graphics_library(),
        ],
        'general_stats' => get_pwg_general_statitics(),
    ];

    // convert disk_usage from kB to mB
    // get_pwg_general_statitics() is typed array<string, mixed>, so
    // 'disk_usage' comes back mixed even though it's always a numeric
    // byte count in practice.
    $disk_usage_kb = $piwigo_infos['general_stats']['disk_usage'];
    $disk_usage_kb = is_numeric($disk_usage_kb) ? (float) $disk_usage_kb : 0.0;
    $piwigo_infos['general_stats']['disk_usage'] = intval($disk_usage_kb / 1024);

    $piwigo_infos['general_stats']['installed_on'] = get_installation_date();
    $piwigo_infos['general_stats']['nb_photos_synced'] = 0;
    $piwigo_infos['general_stats']['last_photo_synced'] = null;
    $piwigo_infos['general_stats']['last_photo'] = null;

    if ($piwigo_infos['general_stats']['nb_photos'] > 0) {
        $query = '
SELECT
    COUNT(*) AS counter
  FROM `' . Tables::images() . '`
  WHERE storage_category_id IS NOT NULL
;';
        if (query2array($query, null, 'counter')[0] > 0) {
            // slow SQL query, but necessary if you have files added by sync
            $query = '
SELECT
    IF(storage_category_id IS NULL, \'api\', \'sync\') AS add_method,
    MAX(date_available) AS last_added_on,
    COUNT(*) AS nb_files
  FROM `' . Tables::images() . '`
  GROUP BY add_method
;';
            $files_added_by = query2array($query, 'add_method');

            $piwigo_infos['general_stats']['nb_photos_synced'] = $files_added_by['sync']['nb_files'];
            $piwigo_infos['general_stats']['last_photo_synced'] = $files_added_by['sync']['last_added_on'];

            $method_of_last_photo = 'sync';
            if (isset($files_added_by['api']) and strtotime((string) $files_added_by['api']['last_added_on']) > strtotime((string) $files_added_by['sync']['last_added_on'])) {
                $method_of_last_photo = 'api';
            }
            $piwigo_infos['general_stats']['last_photo'] = $files_added_by[$method_of_last_photo]['last_added_on'];
        } else {
            // much faster SQL query, but valid only if you do not use sync to add photos
            $query = '
SELECT
    date_available
  FROM `' . Tables::images() . '`
  ORDER BY id DESC
  LIMIT 1
;';
            $images = query2array($query);
            if (count($images) > 0) {
                $piwigo_infos['general_stats']['last_photo'] = $images[0]['date_available'];
            }
        }

        $query = '
SELECT
    SUBSTRING_INDEX(path,".",-1) AS ext,
    COUNT(*) AS counter,
    SUM(filesize) AS filesize
  FROM `' . Tables::images() . '`
  GROUP BY ext
;';
        $piwigo_infos['file_extensions'] = query2array($query, 'ext');
    }

    // $conf['pem_plugins_category'] = 12;
    // $conf['pem_themes_category'] = 10;
    // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url'])
    // in common.inc.php on one branch, so PHPStan infers the constant's
    // global type as mixed even though it's always a URL string at runtime.
    $pem_url = PEM_URL;
    $pem_url = is_string($pem_url) ? $pem_url : '';
    $url = $pem_url . '/api/get_extension_list.php';
    // $result is never a resource here: no fopen() handle is passed to
    // fetchRemote() above. Seeded as a string so the by-reference $dest
    // parameter isn't inferred as mixed before the call.
    // unserialize() is typed mixed by PHP's own stub; the PEM API's
    // contract is an array of {eid: {...}} extension records, but a
    // malformed/unexpected response must degrade to the retry-later path
    // instead of crashing on a foreach/array-access of something else.
    $pem_extensions = [];
    $pem_decode_ok = false;
    $result = '';
    if (fetchRemote($url, $result) and is_string($result)) {
        $decoded_extensions = @unserialize($result);
        if (is_array($decoded_extensions) and $decoded_extensions !== []) {
            $pem_decode_ok = true;
            foreach ($decoded_extensions as $decoded_eid => $decoded_ext) {
                // $decoded_eid is always int|string (PHP's own array-key
                // invariant for a foreach), so only $decoded_ext needs
                // validating.
                if (is_array($decoded_ext)) {
                    $pem_extensions[$decoded_eid] = $decoded_ext;
                }
            }
        }
    }

    if ($pem_decode_ok) {
        $official_exts = [];
        foreach ($pem_extensions as $eid => $ext) {
            $archive_root_dir = $ext['archive_root_dir'] ?? null;
            $idx_category = $ext['idx_category'] ?? null;
            if (
                ! empty($archive_root_dir)
                and (is_int($idx_category) || is_string($idx_category))
                and (is_int($archive_root_dir) || is_string($archive_root_dir))
            ) {
                @$official_exts[$idx_category][$archive_root_dir] = $eid;
            }
        }
    } else {
        $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] fetchRemote on ' . $url . ' has failed');
        send_piwigo_infos_retry_later(1 * 60 * 60); // 1 hour later
        \Piwigo\Core\UniqueExecLock::ends('send_piwigo_infos');
        $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] executed in ' . \Piwigo\Core\TimingHelper::getElapsedTime($start_time, \Piwigo\Core\TimingHelper::getMoment()));
        return;
    }

    $plugins = new plugins();
    $piwigo_infos['general_stats']['nb_private_plugins'] = 0;
    $piwigo_infos['plugins'] = [];
    foreach ($plugins->db_plugins_by_id as $plugin) {
        if ($plugin['state'] == 'active') {
            // piwigo_plugins.id/version are `varchar(...) NOT NULL` in the
            // schema — a genuine row here always carries strings.
            $plugin_id = $plugin['id'];
            assert(is_string($plugin_id));
            $plugin_version = $plugin['version'];
            assert(is_string($plugin_version));

            $eid = null;
            if (isset($plugins->fs_plugins[$plugin_id])) {
                $uri = $plugins->fs_plugins[$plugin_id]['uri'] ?? null;
                if (is_string($uri) and (bool) preg_match('/eid=(\d+)/', $uri, $matches)) {
                    if (isset($pem_extensions[$matches[1]])) {
                        $eid = $matches[1];
                    }
                }
            }

            if (empty($eid)) {
                // let's search in the data fetched from PEM
                $pem_plugins_category = $conf['pem_plugins_category'];
                $pem_plugins_category = (is_int($pem_plugins_category) || is_string($pem_plugins_category)) ? $pem_plugins_category : 0;
                $eid = $official_exts[$pem_plugins_category][$plugin_id] ?? null;
            }

            // we must exclude "private extensions". A private extension :
            //
            // * has no eid
            // * OR has un unknown plugin_id among all "Archive root directory" in PEM
            if (empty($eid)) {
                $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] ' . $plugin_id . ' is a private plugin, not sent to piwigo.org');
                $piwigo_infos['general_stats']['nb_private_plugins']++;
                continue;
            }

            $codename = $pem_extensions[$eid]['archive_root_dir'] ?? $plugin_id;
            $codename = is_scalar($codename) ? $codename : $plugin_id;

            $piwigo_infos['plugins'][] = '#' . $eid . '/' . $codename . '/' . $plugin_version;
        }
    }

    $piwigo_infos['general_stats']['nb_plugins'] = $piwigo_infos['general_stats']['nb_private_plugins'] + count($piwigo_infos['plugins']);

    $themes = new themes();
    $piwigo_infos['general_stats']['nb_private_themes'] = 0;
    $piwigo_infos['themes'] = [];
    $private_themes = [];
    // piwigo_themes has no 'state' column (confirmed in both
    // install/piwigo_structure-mysql.sql and the test fixture) — unlike
    // plugins, a theme is only in this table while active, so every row
    // here is implicitly active.
    foreach ($themes->db_themes_by_id as $theme) {
        // piwigo_themes.id/version are `varchar(...) NOT NULL` in the
        // schema — a genuine row here always carries strings.
        $theme_id = $theme['id'];
        assert(is_string($theme_id));
        $theme_version = $theme['version'];
        assert(is_string($theme_version));

        $eid = null;
        if (isset($themes->fs_themes[$theme_id])) {
            $uri = $themes->fs_themes[$theme_id]['uri'] ?? null;
            if (is_string($uri) and (bool) preg_match('/eid=(\d+)/', $uri, $matches)) {
                if (isset($pem_extensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
        }

        if (empty($eid)) {
            // let's search in the data fetched from PEM
            $pem_themes_category = $conf['pem_themes_category'];
            $pem_themes_category = (is_int($pem_themes_category) || is_string($pem_themes_category)) ? $pem_themes_category : 0;
            $eid = $official_exts[$pem_themes_category][$theme_id] ?? null;
        }

        // we must exclude "private extensions". A private extension :
        //
        // * has no eid
        // * OR has un unknown theme_id among all "Archive root directory" in PEM
        if (empty($eid)) {
            $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] ' . $theme_id . ' is a private theme, not sent to piwigo.org');
            $private_themes[$theme_id] = 1;
            continue;
        }

        $codename = $pem_extensions[$eid]['archive_root_dir'] ?? $theme_id;
        $codename = is_scalar($codename) ? $codename : $theme_id;

        $piwigo_infos['themes'][] = '#' . $eid . '/' . $codename . '/' . $theme_version;
    }

    $piwigo_infos['general_stats']['nb_private_themes'] = count(array_keys($private_themes));
    $piwigo_infos['general_stats']['nb_themes'] = $piwigo_infos['general_stats']['nb_private_themes'] + count($piwigo_infos['themes']);

    $default_theme = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme();
    if (isset($private_themes[$default_theme])) {
        $default_theme = 'private theme';
    }
    $piwigo_infos['general_stats']['default_theme'] = $default_theme;

    $piwigo_infos['themes_usage'] = [];
    $query = '
SELECT
    theme,
    COUNT(*) AS theme_counter
  FROM ' . Tables::userInfos() . '
  GROUP BY theme
  ORDER BY theme
;';
    $themes_used = query2array($query, 'theme', 'theme_counter');
    // built as a separate local accumulator (rather than mutating
    // $piwigo_infos directly with a dynamic key) so PHPStan keeps tracking
    // a precise array<string, int> type instead of widening the whole
    // $piwigo_infos shape to mixed after a non-literal-key write.
    $themes_usage = [];
    foreach ($themes_used as $theme_used => $counter) {
        if (isset($private_themes[$theme_used])) {
            $theme_used = 'private theme';
        }

        $counter_value = is_numeric($counter) ? (int) $counter : 0;
        $themes_usage[$theme_used] = ($themes_usage[$theme_used] ?? 0) + $counter_value;
    }
    $piwigo_infos['themes_usage'] = $themes_usage;

    $piwigo_infos['general_stats']['default_language'] = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage();

    $query = '
SELECT
    language,
    COUNT(*) AS language_counter
  FROM ' . Tables::userInfos() . '
  GROUP BY language
  ORDER BY language
;';
    $piwigo_infos['languages_usage'] = query2array($query, 'language', 'language_counter');

    $piwigo_infos['activities'] = [];
    $piwigo_infos['general_stats']['nb_activities'] = 0;

    $query = '
SELECT
    object,
    action,
    COUNT(*) AS counter
  FROM ' . Tables::activity() . '
  WHERE object != \'system\'
  GROUP BY object, action
;';
    $activities = query2array($query);
    // 'activities' is heterogeneous by design: every object except 'system'
    // (queried here, WHERE object != 'system') stores a flat action=>counter
    // map; 'system' (queried below) stores an extra label-bucketing level.
    // Built as two separately-shaped accumulators (each internally
    // consistent, so PHPStan can track a precise nested type for each) and
    // merged at the end -- the two queries' WHERE clauses guarantee
    // disjoint $object keys, so the merge never overwrites either side.
    /** @var array<string, array<string, string|null>> $piwigo_activities_flat */
    $piwigo_activities_flat = [];
    // separate local accumulator for the same reason as $themes_usage above.
    $nb_activities = 0;
    foreach ($activities as $activity) {
        $counter_value = is_numeric($activity['counter']) ? (int) $activity['counter'] : 0;
        $nb_activities += $counter_value;

        // piwigo_activity.object/action are `NOT NULL` in the schema.
        $object = $activity['object'];
        assert(is_string($object));
        $action = $activity['action'];
        assert(is_string($action));
        $piwigo_activities_flat[$object][$action] = $activity['counter'];
    }

    $label_for_system_object_id = [
        1 => 'core',
        2 => 'plugin',
        3 => 'theme',
    ];

    $query = '
SELECT
    object,
    object_id,
    action,
    COUNT(*) AS counter
  FROM ' . Tables::activity() . '
  WHERE object = \'system\'
  GROUP BY object, object_id, action
;';
    $activities = query2array($query);
    /** @var array<string, array<string, array<string, string|null>>> $piwigo_activities_system */
    $piwigo_activities_system = [];
    foreach ($activities as $activity) {
        // piwigo_activity.object/object_id/action are `NOT NULL` in the schema.
        $object = $activity['object'];
        assert(is_string($object));
        $object_id = $activity['object_id'];
        assert(is_numeric($object_id));
        $action = $activity['action'];
        assert(is_string($action));

        $label = $label_for_system_object_id[(int) $object_id] ?? 'undefined';
        $piwigo_activities_system[$object][$label][$action] = $activity['counter'];
    }
    $piwigo_infos['activities'] = $piwigo_activities_flat + $piwigo_activities_system;
    $piwigo_infos['general_stats']['nb_activities'] = $nb_activities;

    $query = '
SELECT
    action,
    occured_on,
    details
  FROM ' . Tables::activity() . '
  WHERE object = \'system\'
    AND object_id = ' . ActivitySystem::Core . '
    AND action IN (\'update\', \'autoupdate\')
  ORDER BY activity_id ASC
;';
    $updates = query2array($query);
    foreach ($updates as $update) {
        $update_details = $update['details'];
        if (! is_string($update_details)) {
            continue;
        }

        $details = \Piwigo\Core\ArrayHelper::safeUnserialize($update_details);
        if (! is_array($details)) {
            continue;
        }

        if (isset($details['from_version']) and isset($details['to_version'])) {
            @$piwigo_infos['updates'][] = [
                'action' => $update['action'],
                'occured_on' => $update['occured_on'],
                'from_version' => $details['from_version'],
                'to_version' => $details['to_version'],
            ];
        }
    }

    $watermark = ImageStdParams::get_watermark();

    $piwigo_infos['features'] = [
        'use_watermark' => ! empty($watermark->file) ? 'yes' : 'no',
    ];

    // which remote apps have been used?
    $remote_apps_start_time = \Piwigo\Core\TimingHelper::getMoment();

    $query = '
SELECT
    user_agent,
    COUNT(*) AS counter,
    MIN(occured_on) AS first_encounter,
    MAX(occured_on) AS last_encounter
  FROM ' . Tables::activity() . '
  WHERE user_agent NOT LIKE \'Mozilla/5%\'
  GROUP BY user_agent
;';
    $activities = query2array($query);
    $apps = [];

    $apps_pattern = [
        'Piwigo iOS' => '/^Piwigo\/\d+ CFNetwork/',
        'Piwigo NG' => '/^Dart\/[\d\.]+ \(dart:io\)$/',
        'Piwigo Android' => '/^Piwigo-Android/',
        'Lightroom' => '/Lightroom/',
        'Piwigo Remote Sync' => '/(PiwigoRemoteSync|Apache-HttpClient)/',
        'darktable' => '/darktable/',
        'Piwigo Client' => '/PiwigoClient/',
        'Aperture' => '/ApertureToPiwigoPlugIn/',
        'MacShare' => '/MacShareToPiwigo/',
        'WordPress' => '/WordPress/',
        'pLoader' => '/pLoader/',
    ];

    foreach ($activities as $activity) {
        foreach ($apps_pattern as $app_name => $pattern) {
            if ((bool) preg_match($pattern, (string) $activity['user_agent'])) {
                // $apps is written with a dynamic ($app_name) key, so PHPStan
                // can't track a precise per-key value type for it and every
                // read below comes back mixed; narrow explicitly instead of
                // bare-casting.
                $counter_value = is_numeric($activity['counter']) ? (int) $activity['counter'] : 0;
                $current_counter = $apps[$app_name]['counter'] ?? 0;
                $current_counter = is_numeric($current_counter) ? (int) $current_counter : 0;
                $apps[$app_name]['counter'] = $current_counter + $counter_value;

                $activity_first_encounter = is_string($activity['first_encounter']) ? $activity['first_encounter'] : '';
                $known_first_encounter = $apps[$app_name]['first_encounter'] ?? null;
                $known_first_encounter = is_string($known_first_encounter) ? $known_first_encounter : null;
                if ($known_first_encounter === null or strtotime($known_first_encounter) > strtotime($activity_first_encounter)) {
                    $apps[$app_name]['first_encounter'] = $activity['first_encounter'];
                }

                $activity_last_encounter = is_string($activity['last_encounter']) ? $activity['last_encounter'] : '';
                $known_last_encounter = $apps[$app_name]['last_encounter'] ?? null;
                $known_last_encounter = is_string($known_last_encounter) ? $known_last_encounter : null;
                if ($known_last_encounter === null or strtotime($known_last_encounter) < strtotime($activity_last_encounter)) {
                    $apps[$app_name]['last_encounter'] = $activity['last_encounter'];
                }
            }
        }
    }

    $piwigo_infos['apps'] = $apps;

    $features = [
        'activate_comments',
        'rate',
        'log',
        'history_guest',
        'history_admin',
    ];

    foreach ($features as $feature) {
        $piwigo_infos['features'][$feature] = ((bool) $conf[$feature]) ? 'yes' : 'no';
    }

    // conf_get_param() reads $conf with a dynamic (non-literal) key, so its
    // return stays mixed even though this param is always a URL string.
    $update_url_base = conf_get_param('send_piwigo_infos_update_url', PHPWG_URL);
    $update_url_base = is_string($update_url_base) ? $update_url_base : PHPWG_URL;
    $url = $update_url_base . '/ws.php';

    $get_data = [
        'format' => 'php',
        'method' => 'porg.installs.update',
        'origin_hash' => $piwigo_infos['origin_hash'],
    ];

    $post_data = [
        'data' => json_encode($piwigo_infos),
    ];

    if (! fetchRemote($url, $result, $get_data, $post_data)) {
        $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
        send_piwigo_infos_retry_later(24 * 60 * 60);
    } else {
        $last_notice = date('c');
        conf_update_param('send_piwigo_infos_last_notice', $last_notice, true);
        $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] fetchRemote success, new send_piwigo_infos_last_notice=' . $last_notice);
    }

    \Piwigo\Core\UniqueExecLock::ends('send_piwigo_infos');
    $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] executed in ' . \Piwigo\Core\TimingHelper::getElapsedTime($start_time, \Piwigo\Core\TimingHelper::getMoment()));
}

function send_piwigo_infos_retry_later(int $wait_time): void
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    // let's fake a last_notice so that we only try 1 day later
    $existing_last_notice = $conf['send_piwigo_infos_last_notice'] ?? null;
    $last_notice = is_string($existing_last_notice) ? strtotime($existing_last_notice) : time();
    $last_notice = ($last_notice === false ? time() : $last_notice) + $wait_time;

    $new_last_notice = date('c', $last_notice);
    conf_update_param('send_piwigo_infos_last_notice', $new_last_notice, true);
    $logger->info('[' . __FUNCTION__ . '] new send_piwigo_infos_last_notice=' . $new_last_notice);
}
