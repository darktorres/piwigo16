<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Gettext\Loader\PoLoader;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\DateService;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Lang;
use Piwigo\Core\LanguageStack;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\QueryHelper;
use Piwigo\Html\HtmlService;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Language\LanguageRepository;
use Piwigo\Mail\MailService;
use Piwigo\Menu\BlockManager;
use Piwigo\Metadata\MetadataService;
use Piwigo\Notification\NotificationService;
use Piwigo\Picture\PictureService;
use Piwigo\Rate\RateService;
use Piwigo\Session\PwgSession;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                        |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

require_once(PHPWG_ROOT_PATH . 'include/functions_plugins.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/functions_user.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/functions_cookie.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/functions_url.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/derivative_params.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
require_once(PHPWG_ROOT_PATH . 'include/derivative.inc.php');

// ── StringUtil delegates ──────────────────────────────────────────────────

function micro_seconds(): string
{
    return ServiceLocator::get(StringUtil::class)->microSeconds();
}

function get_moment(): float
{
    return ServiceLocator::get(StringUtil::class)->getMoment();
}

function get_elapsed_time(mixed $start, mixed $end): string
{
    return ServiceLocator::get(StringUtil::class)->getElapsedTime(
        is_numeric($start) ? (float) $start : 0.0,
        is_numeric($end) ? (float) $end : 0.0
    );
}

/** @param array<string,mixed> $source */
function input_int(string $key, ?int $default = null, array $source = []): ?int
{
    return ServiceLocator::get(StringUtil::class)->inputInt($key, $default, $source);
}

/** @param array<string,mixed> $source */
function input_string(string $key, ?string $default = null, array $source = []): ?string
{
    return ServiceLocator::get(StringUtil::class)->inputString($key, $default, $source);
}

/** @param array<string,mixed> $source */
function input_bool(string $key, ?bool $default = null, array $source = []): ?bool
{
    return ServiceLocator::get(StringUtil::class)->inputBool($key, $default, $source);
}

function get_extension(mixed $filename): string
{
    return ServiceLocator::get(StringUtil::class)->getExtension(is_scalar($filename) ? (string) $filename : '');
}

function get_filename_wo_extension(mixed $filename): string
{
    return ServiceLocator::get(StringUtil::class)->getFilenameWoExtension(is_scalar($filename) ? (string) $filename : '');
}

function get_name_from_file(mixed $filename): string
{
    return ServiceLocator::get(StringUtil::class)->getNameFromFile(is_scalar($filename) ? (string) $filename : '');
}

// ── MKGETDIR constants + delegate ─────────────────────────────────────────

/** no option for mkgetdir() */
define('MKGETDIR_NONE', 0);
/** sets mkgetdir() recursive */
define('MKGETDIR_RECURSIVE', 1);
/** sets mkgetdir() exit script on error */
define('MKGETDIR_DIE_ON_ERROR', 2);
/** sets mkgetdir() add a index.htm file */
define('MKGETDIR_PROTECT_INDEX', 4);
/** sets mkgetdir() add a .htaccess file */
define('MKGETDIR_PROTECT_HTACCESS', 8);
/** default options for mkgetdir() */
define('MKGETDIR_DEFAULT', MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX);

function mkgetdir(mixed $dir, mixed $flags = MKGETDIR_DEFAULT): bool
{
    // pre-boot standalone — called from install.php/upgrade.php/i.php before Kernel::boot()
    if (!ServiceLocator::has(Util::class)) {
        $d = is_scalar($dir) ? (string) $dir : '';
        $f = is_numeric($flags) ? (int) $flags : MKGETDIR_DEFAULT;
        if (!is_dir($d)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $d = str_replace('/', DIRECTORY_SEPARATOR, $d);
            }
            $umask = umask(0);
            set_error_handler(static fn (): bool => true);
            try {
                $mkd = mkdir($d, Config::chmodValue(), ($f & MKGETDIR_RECURSIVE) ? true : false);
            } finally {
                restore_error_handler();
            }
            umask($umask);
            if ($mkd == false) {
                if (!($f & MKGETDIR_DIE_ON_ERROR)) {
                    return false;
                }
                fatal_error("$d " . l10n('no write access'));
            }
            if ($f & MKGETDIR_PROTECT_HTACCESS) {
                $file = $d . '/.htaccess';
                if (!file_exists($file) && is_writable($d)) {
                    file_put_contents($file, 'deny from all');
                }
            }
            if ($f & MKGETDIR_PROTECT_INDEX) {
                $file = $d . '/index.htm';
                if (!file_exists($file) && is_writable($d)) {
                    file_put_contents($file, 'Not allowed!');
                }
            }
        }
        if (!is_writable($d)) {
            if (!($f & MKGETDIR_DIE_ON_ERROR)) {
                return false;
            }
            fatal_error("$d " . l10n('no write access'));
        }
        return true;
    }
    return ServiceLocator::get(Util::class)->mkgetdir(is_scalar($dir) ? (string) $dir : '', is_numeric($flags) ? (int) $flags : MKGETDIR_DEFAULT);
}

// ── Charset delegates ─────────────────────────────────────────────────────

function qualify_utf8(string $Str): int
{
    return ServiceLocator::get(StringUtil::class)->qualifyUtf8($Str);
}

function remove_accents(string $string): string
{
    return ServiceLocator::get(StringUtil::class)->removeAccents($string);
}

function pwg_transliterate(string $term): string
{
    return ServiceLocator::get(StringUtil::class)->pwgTransliterate($term);
}

function str2url(string $str): string
{
    return ServiceLocator::get(StringUtil::class)->str2url($str);
}

// ── Language list (pre-boot-safe: has ServiceLocator::has() guard) ────────

/** @return string[] */
function get_languages(): array
{
    if (ServiceLocator::has(LanguageRepository::class)) {
        $languages = [];
        foreach (ServiceLocator::get(LanguageRepository::class)->findIdNameMap() as $id => $name) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }
    $languages = [];
    foreach (DbConnection::build()
        ->executeQuery('SELECT id, name FROM ' . LANGUAGES_TABLE . ' ORDER BY name ASC')
        ->fetchAllAssociative() as $row) {
        $langId   = is_scalar($row['id']) ? (string) $row['id'] : '';
        $langName = is_scalar($row['name']) ? (string) $row['name'] : '';
        if ($langId !== '' && is_dir(PHPWG_ROOT_PATH . 'language/' . $langId)) {
            $languages[$langId] = $langName;
        }
    }
    return $languages;
}

// ── Logging / activity delegates ──────────────────────────────────────────

function do_log(mixed $image_id = null, mixed $image_type = null): bool
{
    return ServiceLocator::get(Util::class)->doLog($image_id, $image_type);
}

function pwg_log(int|string|null $image_id = null, ?string $image_type = null, ?string $format_id = null): bool
{
    return ServiceLocator::get(Util::class)->pwgLog($image_id, $image_type, $format_id);
}

/**
 * @param int[]|int|string $object_id
 * @param array<mixed> $details
 */
function pwg_activity(string $object, array|int|string $object_id, string $action, array $details = []): void
{
    // pre-boot standalone — Util::pwgActivity() uses neither $this->conn nor $this->log
    if (!ServiceLocator::has(Util::class)) {
        (new Util(DbConnection::build(), new \Psr\Log\NullLogger()))->pwgActivity($object, $object_id, $action, $details);
        return;
    }
    ServiceLocator::get(Util::class)->pwgActivity($object, $object_id, $action, $details);
}

// ── Date delegates ────────────────────────────────────────────────────────

function dateDiff(\DateTime $date1, \DateTime $date2): \DateInterval
{
    return ServiceLocator::get(DateService::class)->dateDiff($date1, $date2);
}

function str2DateTime(int|string|null $original, mixed $format = null): \DateTime|false
{
    return ServiceLocator::get(DateService::class)->str2DateTime($original, $format);
}

/** @param string[]|true|null $show */
function format_date_legacy(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
{
    return ServiceLocator::get(DateService::class)->formatDateLegacy($original, $show, $format);
}

/** @param string[]|true|null $show */
function format_date(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
{
    return ServiceLocator::get(DateService::class)->formatDate($original, $show, $format);
}

function format_fromto(int|string|\DateTime|null $from, int|string|\DateTime|null $to, mixed $full = false): string
{
    return ServiceLocator::get(DateService::class)->formatFromto($from, $to, (bool) $full);
}

function time_since(int|string|null $original, string $stop = 'minute', ?string $format = null, bool $with_text = true, bool $with_week = true, bool $only_last_unit = false): string
{
    return ServiceLocator::get(DateService::class)->timeSince($original, $stop, $format, $with_text, $with_week, $only_last_unit);
}

function transform_date(mixed $original, mixed $format_in, mixed $format_out, mixed $default = null): ?string
{
    return ServiceLocator::get(DateService::class)->transformDate($original, $format_in, $format_out, $default);
}

// ── Debug ─────────────────────────────────────────────────────────────────

function pwg_debug(string $string): void
{
    ServiceLocator::get(Util::class)->pwgDebug($string);
}

// ── Redirect — pre-boot-safe standalone implementations ───────────────────
// Called from common.inc.php before Kernel::boot(). Util::redirect*() is canonical.

function redirect_http(mixed $url): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    $url = html_entity_decode(is_scalar($url) ? (string) $url : '');
    header('Request-URI: ' . $url);
    header('Content-Location: ' . $url);
    header('Location: ' . $url);
    exit();
}

function redirect_html(mixed $url, mixed $msg = '', mixed $refresh_time = 0): void
{
    if (!LanguageStack::initialized() || !TemplateRegistry::isInitialized()) {
        CurrentUser::setRawAttributes(build_user(Config::guestId(), true));
        load_language('common.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
        $tpl = new Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
        TemplateRegistry::set($tpl);
    } elseif (defined('IN_ADMIN') && IN_ADMIN) {
        $tpl = new Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
        TemplateRegistry::set($tpl);
    }

    if (empty($msg)) {
        $msg = nl2br(l10n('Redirection...'));
    }

    $refresh  = is_numeric($refresh_time) ? (int) $refresh_time : 0;
    $url_link = is_scalar($url) ? (string) $url : '';
    $title    = 'redirection';

    $tpl = TemplateRegistry::current();
    $tpl->set_filenames(['redirect' => 'redirect.tpl']);

    require(PHPWG_ROOT_PATH . 'include/page_header.php');

    $tpl->set_filenames(['redirect' => 'redirect.tpl']);
    $tpl->assign('REDIRECT_MSG', $msg);
    $tpl->parse('redirect');

    require(PHPWG_ROOT_PATH . 'include/page_tail.php');
    exit();
}

function redirect(mixed $url, mixed $msg = '', mixed $refresh_time = 0): void
{
    $urlStr      = is_scalar($url) ? (string) $url : '';
    $msgStr      = is_scalar($msg) ? (string) $msg : '';
    $refreshTime = is_numeric($refresh_time) ? (int) $refresh_time : 0;
    if (Config::defaultRedirectMethod() === 'http' && $refreshTime === 0 && !headers_sent()) {
        redirect_http($urlStr);
    } else {
        redirect_html($urlStr, $msgStr, $refreshTime);
    }
}

// ── Theme delegates ───────────────────────────────────────────────────────

/** @return array<string,string> */
function get_pwg_themes(bool $show_mobile = false): array
{
    return ServiceLocator::get(Util::class)->getPwgThemes($show_mobile);
}

function check_theme_installed(string $theme_id): bool
{
    return ServiceLocator::get(Util::class)->checkThemeInstalled($theme_id);
}

function original_to_representative(mixed $path, mixed $representative_ext): string
{
    return ServiceLocator::get(StringUtil::class)->originalToRepresentative(is_scalar($path) ? (string) $path : '', is_scalar($representative_ext) ? (string) $representative_ext : '');
}

function original_to_format(mixed $path, mixed $format_ext): string
{
    return ServiceLocator::get(StringUtil::class)->originalToFormat(is_scalar($path) ? (string) $path : '', is_scalar($format_ext) ? (string) $format_ext : '');
}

/** @param array<string,mixed> $element_info */
function get_element_path(array $element_info): string
{
    return ServiceLocator::get(StringUtil::class)->getElementPath($element_info);
}

/** @param int[] $elements_id */
function fill_caddie(array $elements_id): void
{
    ServiceLocator::get(Util::class)->fillCaddie($elements_id);
}

function get_name_from_file_delegate(mixed $filename): string
{
    return get_name_from_file(is_scalar($filename) ? (string) $filename : '');
}

// ── i18n — pre-boot-safe standalone implementations ──────────────────────
// All called before Kernel::boot() via common.inc.php / redirect_html.
// LangService::l10n() etc. are canonical copies.

function l10n(?string $key): string
{
    $args = func_num_args() > 1 ? array_slice(func_get_args(), 1) : [];
    return Lang::t($key ?? '', ...$args);
}

function l10n_dec(string $singular_key, string $plural_key, int|float|null $decimal): string
{
    return Translator::get()->plural($singular_key, $plural_key, (int) $decimal);
}

/** @return array<mixed> */
function get_l10n_args(string $key, mixed $args = ''): array
{
    if (is_array($args)) {
        $key_arg = array_merge([$key], $args);
    } else {
        $key_arg = [$key, $args];
    }
    return ['key_args' => $key_arg];
}

/** @param array<mixed>|string $key_args */
function l10n_args(array|string $key_args, string $sep = "\n"): string
{
    $result = '';
    if (is_array($key_args)) {
        foreach ($key_args as $key => $element) {
            if ($result !== '') {
                $result .= $sep;
            }
            if ($key === 'key_args') {
                $element = is_array($element) ? $element : [];
                $shifted = array_shift($element);
                array_unshift($element, l10n(is_string($shifted) ? $shifted : ''));
                $formatted = call_user_func_array(sprintf(...), $element);
                $result   .= is_scalar($formatted) ? (string) $formatted : '';
            } else {
                /** @var array<mixed>|string $element */
                $result .= l10n_args($element, $sep);
            }
        }
    } else {
        fatal_error('l10n_args: Invalid arguments');
    }
    return $result;
}

function get_themeconf(mixed $key): mixed
{
    return ServiceLocator::get(Util::class)->getThemeconf(is_scalar($key) ? (string) $key : '');
}

function get_webmaster_mail_address(): string
{
    return ServiceLocator::get(Util::class)->getWebmasterMailAddress();
}

// ── Config DB — pre-boot-safe standalone for load_conf_from_db ───────────
// load_conf_from_db is called at common.inc.php:124 before Kernel::boot().
// ConfigService::loadConfFromDb() is the canonical copy.

function load_conf_from_db(?string $condition = '', bool $die_on_condition_with_no_result = true): void
{
    $sql = 'SELECT param, value FROM ' . CONFIG_TABLE .
        (!empty($condition) ? ' WHERE ' . $condition : '');

    if (ServiceLocator::has(Connection::class)) {
        $conn = ServiceLocator::get(Connection::class);
    } else {
        $conn = DbConnection::build();
    }

    $rows = $conn->executeQuery($sql)->fetchAllAssociative();

    if (count($rows) === 0 && !empty($condition) && $die_on_condition_with_no_result) {
        fatal_error('No configuration data');
    }

    foreach ($rows as $row) {
        $val = $row['value'] ?? '';
        if ($val === 'true') {
            $val = true;
        } elseif ($val === 'false') {
            $val = false;
        }
        Config::override(is_scalar($row['param']) ? (string) $row['param'] : '', $val);
    }

    trigger_notify('load_conf', $condition);
}

function pwg_is_dbconf_writeable(): bool
{
    return ServiceLocator::get(ConfigService::class)->pwgIsDbconfWriteable();
}

/** @param callable-string|null $parser */
function conf_update_param(string $param, mixed $value, bool $updateGlobal = false, ?string $parser = null): void
{
    // pre-boot standalone — called from install.php after DB creation but before Kernel::boot()
    if (!ServiceLocator::has(ConfigService::class)) {
        (new ConfigService(DbConnection::build()))->confUpdateParam($param, $value, $updateGlobal, $parser);
        return;
    }
    ServiceLocator::get(ConfigService::class)->confUpdateParam($param, $value, $updateGlobal, $parser);
}

/** @param string|string[] $params */
function conf_delete_param(string|array $params): void
{
    // pre-boot standalone — same context as conf_update_param
    if (!ServiceLocator::has(ConfigService::class)) {
        (new ConfigService(DbConnection::build()))->confDeleteParam($params);
        return;
    }
    ServiceLocator::get(ConfigService::class)->confDeleteParam($params);
}

function conf_get_param(mixed $param, mixed $default_value = null): mixed
{
    // pre-boot standalone — Config::raw() is always available without the container
    if (!ServiceLocator::has(ConfigService::class)) {
        return Config::raw(is_scalar($param) ? (string) $param : '') ?? $default_value;
    }
    return ServiceLocator::get(ConfigService::class)->confGetParam(is_scalar($param) ? (string) $param : '', $default_value);
}

// ── Safe decode helpers ───────────────────────────────────────────────────

/**
 * Called from ImageStdParams::load_from_db() before Kernel::boot() — must
 * have its own implementation. StringUtil::safeUnserialize() is canonical.
 *
 * @param array<mixed>|string $value
 * @return array<mixed>
 */
function safe_unserialize(array|string $value): array
{
    if (is_string($value)) {
        set_error_handler(static fn (): bool => true);
        try {
            $unserialized = unserialize($value);
            if (!is_array($unserialized) && $value !== '') {
                $unserialized = unserialize(stripslashes($value));
            }
        } finally {
            restore_error_handler();
        }
        return is_array($unserialized) ? $unserialized : [];
    }
    return $value;
}

/**
 * @param array<string,mixed>|null $image_info
 * @param-out array<string,mixed> $image_info
 * @return array<int|string,mixed>|false
 */
function pwg_safe_getimagesize(string $filename, ?array &$image_info = null): array|false
{
    return ServiceLocator::get(StringUtil::class)->pwgSafeGetimagesize($filename, $image_info);
}

/** @return array<string,mixed>|false */
function pwg_safe_exif_read_data(string $filename): array|false
{
    return ServiceLocator::get(StringUtil::class)->pwgSafeExifReadData($filename);
}

/**
 * @param array<mixed>|string $value
 * @return array<mixed>
 */
function safe_json_decode(array|string $value): array
{
    return ServiceLocator::get(StringUtil::class)->safeJsonDecode($value);
}

/**
 * @param string[] $array
 * @return string[]
 */
function prepend_append_array_items(array $array, string $prepend_str, string $append_str): array
{
    return ServiceLocator::get(StringUtil::class)->prependAppendArrayItems($array, $prepend_str, $append_str);
}

// ── Deprecated DB helpers (QueryHelper delegates) ─────────────────────────

/** @return array<mixed> */
#[\Deprecated(message: '2.6')]
function simple_hash_from_query(string $query, string $keyname, string $valuename): array
{
    return ServiceLocator::get(QueryHelper::class)->simpleHashFromQuery($query, $keyname, $valuename);
}

/** @return array<mixed> */
#[\Deprecated(message: '2.6')]
function hash_from_query(string $query, string $keyname): array
{
    return ServiceLocator::get(QueryHelper::class)->hashFromQuery($query, $keyname);
}

/** @return array<mixed> */
#[\Deprecated(message: '2.6')]
function array_from_query(string $query, string|false $fieldname = false): array
{
    return ServiceLocator::get(QueryHelper::class)->arrayFromQuery($query, $fieldname);
}

// ── Misc string/util delegates ────────────────────────────────────────────

function script_basename(): string
{
    // pre-boot standalone — called from Template::set_theme() in install.php / upgrade.php
    if (!ServiceLocator::has(StringUtil::class)) {
        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $value) {
            if (!empty($_SERVER[$value])) {
                $filename = strtolower(is_scalar($_SERVER[$value]) ? (string) $_SERVER[$value] : '');
                $ext = strrchr($filename, '.');
                if (Config::phpExtensionInUrls() && ($ext === false ? '' : substr($ext, 1)) !== 'php') {
                    continue;
                }
                $basename = basename($filename, '.php');
                if (!empty($basename)) {
                    return $basename;
                }
            }
        }
        return '';
    }
    return ServiceLocator::get(StringUtil::class)->scriptBasename();
}

function get_filter_page_value(mixed $value_name): mixed
{
    return ServiceLocator::get(Util::class)->getFilterPageValue(is_scalar($value_name) ? (string) $value_name : '');
}

function get_pwg_charset(): string
{
    return ServiceLocator::get(StringUtil::class)->getPwgCharset();
}

// ── Language loading — pre-boot-safe standalone ───────────────────────────
// Called from redirect_html() and common.inc.php before Kernel::boot().
// LangService::getParentLanguage/loadLanguage are canonical copies.

function get_parent_language(mixed $lang_id = null): ?string
{
    if (empty($lang_id)) {
        $info   = LanguageStack::info();
        $parent = $info['parent'] ?? null;
        return is_string($parent) && $parent !== '' ? $parent : null;
    }
    $f = PHPWG_ROOT_PATH . 'language/' . (is_scalar($lang_id) ? (string) $lang_id : '') . '/common.lang.php';
    if (file_exists($f)) {
        require($f);
        /** @var array<string,mixed> $lang_info */
        return !empty($lang_info['parent']) && is_string($lang_info['parent']) ? $lang_info['parent'] : null;
    }
    return null;
}

/** @param array<mixed> $options */
function load_language(string $filename, string $dirname = '', array $options = []): string|bool
{
    $user = CurrentUser::isInitialized()
        ? CurrentUser::get()->rawAttributes
        : (is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : []);

    if (!empty($dirname) && !empty($filename) && empty($options['return'])
        && !LanguageStack::hasPluginFile($dirname, $filename)) {
        LanguageStack::trackPluginFile($dirname, $filename, $options);
    }

    if (empty($dirname)) {
        $dirname = PHPWG_ROOT_PATH;
    }
    $langDir = $dirname . 'language/';

    $default_language = (InstallSentinel::isInstalled() && !defined('UPGRADES_PATH'))
        ? get_default_language()
        : PHPWG_DEFAULT_LANGUAGE;

    $languages = [];
    if (!empty($options['language'])) {
        $languages[] = $options['language'];
    }
    if (!empty($user['language'])) {
        $languages[] = $user['language'];
    }
    if (($parent = get_parent_language()) !== null) {
        $languages[] = $parent;
    }
    if (isset($options['force_fallback'])) {
        $languages[] = $options['force_fallback'] === true ? $default_language : $options['force_fallback'];
    }
    if (empty($options['no_fallback'])) {
        $languages[] = $default_language;
    }

    /** @var list<string> $languages_typed */
    $languages_typed = array_values(array_unique(array_filter($languages, is_string(...))));

    if (!empty($options['return'])) {
        foreach ($languages_typed as $language) {
            $f = !empty($options['local'])
                ? $langDir . $language . '.' . $filename
                : $langDir . $language . '/' . $filename;
            if (is_readable($f)) {
                return file_get_contents($f);
            }
        }
        return false;
    }

    if (!empty($options['local'])) {
        foreach ($languages_typed as $language) {
            $f = $langDir . $language . '.' . $filename . '.php';
            if (is_readable($f)) {
                $lang      = null;
                $lang_info = null;
                include $f;
                LanguageStack::mergeLang((array) $lang);
                LanguageStack::mergeInfo((array) $lang_info);
                return true;
            }
        }
        return false;
    }

    $domain = (string) preg_replace('/\.lang$/', '', $filename);
    $po_file           = '';
    $selected_language = '';
    foreach ($languages_typed as $language) {
        $candidate = $langDir . $language . '/' . $domain . '.po';
        if (file_exists($candidate)) {
            $selected_language = $language;
            $po_file           = $candidate;
            break;
        }
    }

    if ($selected_language === '' || !is_readable($po_file)) {
        return false;
    }

    Translator::get()->load($selected_language, $po_file);

    $poHeaders    = new PoLoader()->loadFile($po_file)->getHeaders();
    $lang_info_po = [];
    foreach ([
        'X-Piwigo-Language-Name' => 'language_name',
        'X-Piwigo-Country'       => 'country',
        'X-Piwigo-Direction'     => 'direction',
        'X-Piwigo-Code'          => 'code',
    ] as $header => $key) {
        if (($v = $poHeaders->get($header)) !== null) {
            $lang_info_po[$key] = $v;
        }
    }
    if (($v = $poHeaders->get('X-Piwigo-Zero-Plural')) !== null) {
        $lang_info_po['zero_plural'] = ($v === 'true');
    }
    LanguageStack::mergeInfo($lang_info_po);

    return true;
}

function convert_charset(string $str, string $source_charset, string $dest_charset): string
{
    if ($source_charset === $dest_charset) {
        return $str;
    }
    return ServiceLocator::get(StringUtil::class)->convertCharset($str, $source_charset, $dest_charset);
}

function secure_directory(string $dir): void
{
    ServiceLocator::get(StringUtil::class)->secureDirectory($dir);
}

function get_ephemeral_key(int $valid_after_seconds, string $aditionnal_data_to_hash = ''): string
{
    return ServiceLocator::get(Util::class)->getEphemeralKey($valid_after_seconds, $aditionnal_data_to_hash);
}

function verify_ephemeral_key(string $key, string $aditionnal_data_to_hash = ''): bool
{
    return ServiceLocator::get(Util::class)->verifyEphemeralKey($key, $aditionnal_data_to_hash);
}

/** @return array<mixed> */
function create_navigation_bar(string $url, int $nb_element, int $start, int $nb_element_page, bool $clean_url = false, string $param_name = 'start'): array
{
    return ServiceLocator::get(Util::class)->createNavigationBar($url, $nb_element, $start, $nb_element_page, $clean_url, $param_name);
}

/** @return array<mixed>|false */
function get_icon(?string $date, bool $is_child_date = false): false|array
{
    return ServiceLocator::get(Util::class)->getIcon($date, $is_child_date);
}

function check_pwg_token(): void
{
    ServiceLocator::get(Util::class)->checkPwgToken();
}

function get_pwg_token(): string
{
    return ServiceLocator::get(Util::class)->getPwgToken();
}

/** @param array<mixed> $param_array */
function check_input_parameter(string $param_name, array $param_array, bool $is_array, ?string $pattern, bool $mandatory = false): bool
{
    return ServiceLocator::get(Util::class)->checkInputParameter($param_name, $param_array, $is_array, $pattern, $mandatory);
}

/** @return string[] */
function get_privacy_level_options(): array
{
    return ServiceLocator::get(Util::class)->getPrivacyLevelOptions();
}

// ── get_branch_from_version — pre-boot-safe standalone ───────────────────
// Called at common.inc.php:139 before Kernel::boot().
// StringUtil::getBranchFromVersion() is the canonical copy.

function get_branch_from_version(mixed $version): string
{
    return implode('.', array_slice(explode('.', is_scalar($version) ? (string) $version : ''), 0, 1));
}

function get_device(): string
{
    return ServiceLocator::get(Util::class)->getDevice();
}

function mobile_theme(): bool
{
    return ServiceLocator::get(Util::class)->mobileTheme();
}

function url_check_format(mixed $url): bool
{
    return ServiceLocator::get(StringUtil::class)->urlCheckFormat(is_scalar($url) ? (string) $url : '');
}

function email_check_format(mixed $mail_address): bool
{
    return ServiceLocator::get(StringUtil::class)->emailCheckFormat(is_scalar($mail_address) ? (string) $mail_address : '');
}

function get_nb_available_comments(): int
{
    return ServiceLocator::get(Util::class)->getNbAvailableComments();
}

function safe_version_compare(mixed $a, mixed $b, mixed $op = null): int|bool
{
    return ServiceLocator::get(StringUtil::class)->safeVersionCompare($a, $b, $op);
}

function check_lounge(): void
{
    ServiceLocator::get(Util::class)->checkLounge();
}

function send_piwigo_infos(): void
{
    ServiceLocator::get(Util::class)->sendPiwigoInfos();
}

function send_piwigo_infos_retry_later(int $wait_time): void
{
    ServiceLocator::get(Util::class)->sendPiwigoInfosRetryLater($wait_time);
}

function pwg_unique_exec_begins(string $token_name, int $timeout = 60): false|string
{
    return ServiceLocator::get(Util::class)->pwgUniqueExecBegins($token_name, $timeout);
}

function pwg_unique_exec_is_running(string $token_name): bool
{
    return ServiceLocator::get(Util::class)->pwgUniqueExecIsRunning($token_name);
}

function pwg_unique_exec_ends(string $token_name): void
{
    ServiceLocator::get(Util::class)->pwgUniqueExecEnds($token_name);
}

/** @return array{0: string, 1: string|null} */
function get_container_info(): array
{
    return ServiceLocator::get(StringUtil::class)->getContainerInfo();
}

function is_valid_mysql_datetime(string $datetime): bool
{
    return ServiceLocator::get(StringUtil::class)->isValidMysqlDatetime($datetime);
}

// ── Session delegates (inlined from functions_session.inc.php) ────────────

// Config class may not be autoloaded yet during install.php bootstrap.
if (class_exists(Config::class, false)
  and Config::has('session_save_handler')
  and (Config::sessionSaveHandler() == 'db')
  and InstallSentinel::isInstalled()) {
    session_set_save_handler(new PwgSession());

    if (function_exists('ini_set')) {
        ini_set('session.use_cookies', Config::sessionUseCookies());
        ini_set('session.use_only_cookies', Config::sessionUseOnlyCookies());
        ini_set('session.use_trans_sid', intval(Config::sessionUseTransSid()));
        ini_set('session.cookie_httponly', 1);
    }

    session_name(Config::sessionName());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'samesite' => 'Strict',
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on',
    ]);
    register_shutdown_function(session_write_close(...));
}

/**
 * Called before Kernel::boot() in common.inc.php — must have its own
 * implementation. SessionService::generateKey() is the canonical copy.
 */
function generate_key(int $size): string
{
    $bytes = random_bytes(max(1, $size + 10));
    return substr(str_replace(['+', '/'], '', base64_encode($bytes)), 0, $size);
}

function pwg_session_open(string $path, string $name): bool
{
    return ServiceLocator::get(SessionService::class)->sessionOpen($path, $name);
}

function pwg_session_close(): bool
{
    return ServiceLocator::get(SessionService::class)->sessionClose();
}

function get_remote_addr_session_hash(): string
{
    return ServiceLocator::get(SessionService::class)->getRemoteAddrSessionHash();
}

function pwg_session_read(string $session_id): string
{
    return ServiceLocator::get(SessionService::class)->sessionRead($session_id);
}

function pwg_session_write(string $session_id, string $data): bool
{
    return ServiceLocator::get(SessionService::class)->sessionWrite($session_id, $data);
}

function pwg_session_destroy(string $session_id): bool
{
    return ServiceLocator::get(SessionService::class)->sessionDestroy($session_id);
}

function pwg_session_gc(): bool
{
    return ServiceLocator::get(SessionService::class)->sessionGc();
}

function pwg_set_session_var(string $var, mixed $value): bool
{
    return ServiceLocator::get(SessionService::class)->setSessionVar($var, $value);
}

function pwg_get_session_var(string $var, mixed $default = null): mixed
{
    return ServiceLocator::get(SessionService::class)->getSessionVar($var, $default);
}

function pwg_unset_session_var(string $var): bool
{
    return ServiceLocator::get(SessionService::class)->unsetSessionVar($var);
}

function delete_user_sessions(int $user_id): void
{
    ServiceLocator::get(SessionService::class)->deleteUserSessions($user_id);
}

// ── Category delegates (inlined from functions_category.inc.php) ──────────

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function global_rank_compare(array $a, array $b): int
{
    return ServiceLocator::get(CategoryService::class)->globalRankCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function rank_compare(array $a, array $b): int
{
    return ServiceLocator::get(CategoryService::class)->rankCompare($a, $b);
}

function check_restrictions(int $category_id): void
{
    ServiceLocator::get(CategoryService::class)->checkRestrictions($category_id);
}

/** @return array<mixed> */
function get_categories_menu(): array
{
    return ServiceLocator::get(CategoryService::class)->getCategoriesMenu();
}

/** @return array<mixed>|null */
function get_cat_info(int|string $id): ?array
{
    return ServiceLocator::get(CategoryService::class)->getCatInfo($id);
}

/** @return array<mixed> */
function get_category_preferred_image_orders(): array
{
    return ServiceLocator::get(CategoryService::class)->getCategoryPreferredImageOrders();
}

/**
 * @param list<array<string, mixed>> $categories
 * @param int[]|string               $selecteds
 */
function display_select_categories(array $categories, array|string $selecteds, string $blockname, bool|string $fullname = true): void
{
    ServiceLocator::get(CategoryService::class)->displaySelectCategories($categories, $selecteds, $blockname, $fullname);
}

/** @param int[]|string $selecteds */
function display_select_cat_wrapper(string $query, array|string $selecteds, string $blockname, bool|string $fullname = true): void
{
    ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query, $selecteds, $blockname, $fullname);
}

/**
 * @param array<int|string>|int|string $ids
 * @return array<int>
 */
function get_subcat_ids(array|int|string $ids): array
{
    return ServiceLocator::get(CategoryService::class)->getSubcatIds($ids);
}

/** @param string[] $permalinks */
function get_cat_id_from_permalinks(array $permalinks, int &$idx): ?int
{
    return ServiceLocator::get(CategoryService::class)->getCatIdFromPermalinks($permalinks, $idx);
}

function get_display_images_count(mixed $cat_nb_images, mixed $cat_count_images, mixed $cat_count_categories, bool|string $short_message = true, string $separator = '\n'): string
{
    return ServiceLocator::get(CategoryService::class)->getDisplayImagesCount(
        is_numeric($cat_nb_images) ? (int) $cat_nb_images : 0,
        is_numeric($cat_count_images) ? (int) $cat_count_images : 0,
        is_numeric($cat_count_categories) ? (int) $cat_count_categories : 0,
        $short_message,
        $separator
    );
}

/** @param array<string, mixed> $category */
function get_random_image_in_category(array $category, bool $recursive = true): ?int
{
    return ServiceLocator::get(CategoryService::class)->getRandomImageInCategory($category, $recursive);
}

/**
 * @param array<string, mixed>               $userdata
 * @return array<string, array<string, mixed>>
 */
function get_computed_categories(array &$userdata, ?int $filter_days = null): array
{
    return ServiceLocator::get(CategoryService::class)->getComputedCategories($userdata, $filter_days);
}

/**
 * @param array<string, array<string, mixed>> $cats
 * @param array<string, mixed>                $cat
 */
function remove_computed_category(array &$cats, array $cat): void
{
    ServiceLocator::get(CategoryService::class)->removeComputedCategory($cats, $cat);
}

/**
 * @param int[]|int|string $cat_ids
 * @return int[]
 */
function get_image_ids_for_categories(array|int|string $cat_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', string $order_by = '', bool $use_permissions = true): array
{
    return ServiceLocator::get(CategoryService::class)->getImageIdsForCategories($cat_ids, $mode, $extra_images_where_sql, $order_by, $use_permissions);
}

/**
 * @param array<mixed>  $items
 * @param int[]         $excluded_cat_ids
 * @return array<string, array<string, mixed>>
 */
function get_common_categories(array $items, ?int $max = null, array $excluded_cat_ids = [], bool $use_permissions = true): array
{
    return ServiceLocator::get(CategoryService::class)->getCommonCategories($items, $max, $excluded_cat_ids, $use_permissions);
}

/**
 * @param array<mixed>  $items
 * @param int[]         $excluded_cat_ids
 * @return array<mixed>
 */
function get_related_categories_menu(array $items, array $excluded_cat_ids = []): array
{
    return ServiceLocator::get(CategoryService::class)->getRelatedCategoriesMenu($items, $excluded_cat_ids);
}

// ── Tag delegates (inlined from functions_tag.inc.php) ────────────────────

function get_nb_available_tags(): int
{
    return ServiceLocator::get(TagService::class)->getNbAvailableTags();
}

/**
 * @param int[] $tag_ids
 * @return array<mixed>
 */
function get_available_tags(array $tag_ids = []): array
{
    return ServiceLocator::get(TagService::class)->getAvailableTags($tag_ids);
}

/** @return array<mixed> */
function get_all_tags(): array
{
    return ServiceLocator::get(TagService::class)->getAllTags();
}

/**
 * @param array<mixed> $tags
 * @return array<mixed>
 */
function add_level_to_tags(array $tags): array
{
    return ServiceLocator::get(TagService::class)->addLevelToTags($tags);
}

/**
 * @param int[]|int|string $tag_ids
 * @return int[]
 */
function get_image_ids_for_tags(array|int|string $tag_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', ?string $order_by = '', bool $use_permissions = true): array
{
    return ServiceLocator::get(TagService::class)->getImageIdsForTags($tag_ids, $mode, $extra_images_where_sql, $order_by, $use_permissions);
}

/**
 * @param array<mixed> $items
 * @param int[]        $excluded_tag_ids
 * @return array<mixed>
 */
function get_common_tags(array $items, int $max_tags, array $excluded_tag_ids = []): array
{
    return ServiceLocator::get(TagService::class)->getCommonTags($items, $max_tags, $excluded_tag_ids);
}

/**
 * @param int[]|string[] $ids
 * @param string[]       $url_names
 * @param string[]       $names
 * @return array<mixed>
 */
function find_tags(array $ids = [], array $url_names = [], array $names = []): array
{
    return ServiceLocator::get(TagService::class)->findTags($ids, $url_names, $names);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_id_compare(array $a, array $b): int
{
    return ServiceLocator::get(TagService::class)->tagsIdCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_counter_compare(array $a, array $b): int
{
    return ServiceLocator::get(TagService::class)->tagsCounterCompare($a, $b);
}

// ── HTML delegates (inlined from functions_html.inc.php) ──────────────────

/** @param array<mixed> $cat_informations */
function get_cat_display_name(array $cat_informations, ?string $url = ''): string
{
    return ServiceLocator::get(HtmlService::class)->getCatDisplayName($cat_informations, $url);
}

function get_cat_display_name_cache(
    string $uppercats,
    ?string $url = '',
    bool $single_link = false,
    ?string $link_class = null,
    ?string $auth_key = null
): string {
    return ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache($uppercats, $url, $single_link, $link_class, $auth_key);
}

function get_cat_display_name_from_id(int|string $cat_id, ?string $url = ''): string
{
    return ServiceLocator::get(HtmlService::class)->getCatDisplayNameFromId($cat_id, $url);
}

function render_comment_content(string $content): string|null
{
    return ServiceLocator::get(HtmlService::class)->renderCommentContent($content);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function name_compare(array $a, array $b): int
{
    // pre-boot standalone — called from Languages::get_fs_languages() in install.php / upgrade.php
    if (!ServiceLocator::has(HtmlService::class)) {
        return strcmp(strtolower(is_scalar($a['name']) ? (string) $a['name'] : ''), strtolower(is_scalar($b['name']) ? (string) $b['name'] : ''));
    }
    return ServiceLocator::get(HtmlService::class)->nameCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tag_alpha_compare(array $a, array $b): int
{
    return ServiceLocator::get(HtmlService::class)->tagAlphaCompare($a, $b);
}

function access_denied(): void
{
    ServiceLocator::get(HtmlService::class)->accessDenied();
}

function page_forbidden(string $msg, ?string $alternate_url = null): void
{
    ServiceLocator::get(HtmlService::class)->pageForbidden($msg, $alternate_url);
}

function bad_request(string $msg, ?string $alternate_url = null): void
{
    ServiceLocator::get(HtmlService::class)->badRequest($msg, $alternate_url);
}

function page_not_found(?string $msg, ?string $alternate_url = null): void
{
    ServiceLocator::get(HtmlService::class)->pageNotFound($msg, $alternate_url);
}

/**
 * Called before Kernel::boot() on DB-connect errors — must have its own
 * implementation. HtmlService::fatalError() is the canonical copy.
 */
function fatal_error(string $msg, ?string $title = null, bool $show_trace = true): never
{
    if (empty($title)) {
        $title = function_exists('l10n') ? l10n('Piwigo encountered a non recoverable error') : 'Piwigo encountered a non recoverable error';
    }

    $btraceMsg = '';
    if ($show_trace and function_exists('debug_backtrace')) {
        $bt = debug_backtrace();
        for ($i = 1; $i < count($bt); $i++) {
            $class      = isset($bt[$i]['class']) ? ($bt[$i]['class'] . '::') : '';
            $btraceMsg .= "#$i\t" . $class . $bt[$i]['function'] . ' ' . ($bt[$i]['file'] ?? '') . '(' . ($bt[$i]['line'] ?? '') . ")\n";
        }
        $btraceMsg = trim($btraceMsg);
        $msg .= "\n";
    }

    $display = "<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
<h1>$title</h1>
<pre style='font-size:larger;background:white;color:red;padding:1em;margin:0;clear:both;display:block;width:auto;height:auto;overflow:auto'>
<b>$msg</b>
$btraceMsg
</pre>\n";

    if (!headers_sent()) {
        if (function_exists('set_status_header') && ServiceLocator::has(HtmlService::class)) {
            set_status_header(500);
        } else {
            header('HTTP/1.0 500 Server error', true, 500);
        }
    }
    echo $display . str_repeat(' ', 300);

    if (function_exists('ini_set')) {
        ini_set('display_errors', false);
    }
    error_reporting(E_ALL);
    throw new \RuntimeException(strip_tags($msg) . $btraceMsg);
}

function get_tags_content_title(): string
{
    return ServiceLocator::get(HtmlService::class)->getTagsContentTitle();
}

function get_combined_categories_content_title(): string
{
    return ServiceLocator::get(HtmlService::class)->getCombinedCategoriesContentTitle();
}

function set_status_header(int $code, string $text = ''): void
{
    ServiceLocator::get(HtmlService::class)->setStatusHeader($code, $text);
}

function render_category_literal_description(?string $desc): string
{
    return ServiceLocator::get(HtmlService::class)->renderCategoryLiteralDescription($desc);
}

/** @param BlockManager[] $menu_ref_arr */
function register_default_menubar_blocks(array $menu_ref_arr): void
{
    ServiceLocator::get(HtmlService::class)->registerDefaultMenubarBlocks($menu_ref_arr);
}

/** @param array<string, mixed> $info */
function render_element_name(array $info): string
{
    return ServiceLocator::get(HtmlService::class)->renderElementName($info);
}

/** @param array<string, mixed> $info */
function render_element_description(array $info, string $param = ''): string
{
    return ServiceLocator::get(HtmlService::class)->renderElementDescription($info, $param);
}

/** @param array<string, mixed> $info */
function get_thumbnail_title(array $info, string $title, string $comment = ''): string
{
    return ServiceLocator::get(HtmlService::class)->getThumbnailTitle($info, $title, $comment);
}

function get_src_image_url_protection_handler(string $url, SrcImage $src_image): string
{
    return ServiceLocator::get(HtmlService::class)->getSrcImageUrlProtectionHandler($url, $src_image);
}

/** @param array<string, mixed> $infos */
function get_element_url_protection_handler(string $url, array $infos): string
{
    return ServiceLocator::get(HtmlService::class)->getElementUrlProtectionHandler($url, $infos);
}

function flush_page_messages(): void
{
    ServiceLocator::get(HtmlService::class)->flushPageMessages();
}

function pwg_nl2br(string $string): string
{
    return ServiceLocator::get(HtmlService::class)->pwgNl2br($string);
}

// ── Picture delegates (inlined from functions_picture.inc.php) ────────────

/** @return array<string, mixed> */
function get_default_slideshow_params(): array
{
    return ServiceLocator::get(PictureService::class)->getDefaultSlideshowParams();
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function correct_slideshow_params(array $params = []): array
{
    return ServiceLocator::get(PictureService::class)->correctSlideshowParams($params);
}

/** @return array<string, mixed> */
function decode_slideshow_params(?string $encode_params = null): array
{
    return ServiceLocator::get(PictureService::class)->decodeSlideshowParams($encode_params);
}

/** @param array<string, mixed> $decode_params */
function encode_slideshow_params(array $decode_params = []): string
{
    return ServiceLocator::get(PictureService::class)->encodeSlideshowParams($decode_params);
}

function increase_image_visit_counter(int $image_id): void
{
    ServiceLocator::get(PictureService::class)->increaseImageVisitCounter($image_id);
}

function count_pdf_pages(string $pdfPath): int|false
{
    return ServiceLocator::get(PictureService::class)->countPdfPages($pdfPath);
}

// ── Rate delegates (inlined from functions_rate.inc.php) ──────────────────

/** @return array<mixed>|false */
function rate_picture(int $image_id, float|int|null $rate): array|false
{
    return ServiceLocator::get(RateService::class)->ratePicture($image_id, $rate);
}

/** @return array<mixed> */
function update_rating_score(int|false $element_id = false): array
{
    return ServiceLocator::get(RateService::class)->updateRatingScore($element_id);
}

// ── Metadata delegates (inlined from functions_metadata.inc.php) ──────────

/**
 * @param array<string,string> $map
 * @return array<mixed>
 */
function get_iptc_data(string $filename, array $map, string $array_sep = ','): array
{
    return ServiceLocator::get(MetadataService::class)->getIptcData($filename, $map, $array_sep);
}

function clean_iptc_value(string $value): string
{
    return ServiceLocator::get(MetadataService::class)->cleanIptcValue($value);
}

/**
 * @param array<string,string> $map
 * @return array<mixed>
 */
function get_exif_data(string $filename, array $map): array
{
    return ServiceLocator::get(MetadataService::class)->getExifData($filename, $map);
}

function strip_html_in_metadata(mixed &$v, string $k): void
{
    ServiceLocator::get(MetadataService::class)->stripHtmlInMetadata($v, $k);
}

/** @param array<string> $raw */
function parse_exif_gps_data(array $raw, string $ref): float
{
    return ServiceLocator::get(MetadataService::class)->parseExifGpsData($raw, $ref);
}

// ── Comment delegates (inlined from functions_comment.inc.php) ────────────

add_event_handler('user_comment_check', 'user_comment_check');

/** @param array<string,mixed> $comment */
function user_comment_check(string $action, array $comment): string
{
    return ServiceLocator::get(CommentService::class)->userCommentCheck($action, $comment);
}

/**
 * @param array<string,mixed> $comm
 * @param string[]            $infos
 * @return string validate, moderate, or reject
 */
function insert_user_comment(array &$comm, string $key, array &$infos): string
{
    return ServiceLocator::get(CommentService::class)->insertUserComment($comm, $key, $infos);
}

/** @param int|int[] $comment_id */
function delete_user_comment(int|array $comment_id): bool
{
    return ServiceLocator::get(CommentService::class)->deleteUserComment($comment_id);
}

/**
 * @param array<string,mixed> $comment
 * @return string validate, moderate, or reject
 */
function update_user_comment(array $comment, string $post_key): string
{
    return ServiceLocator::get(CommentService::class)->updateUserComment($comment, $post_key);
}

/** @param array<string,mixed> $comment */
function email_admin(string $action, array $comment): void
{
    ServiceLocator::get(CommentService::class)->emailAdmin($action, $comment);
}

function get_comment_author_id(int $comment_id, bool $die_on_error = true): int|false
{
    return ServiceLocator::get(CommentService::class)->getCommentAuthorId($comment_id, $die_on_error);
}

/** @param int|int[] $comment_id */
function validate_user_comment(int|array $comment_id): void
{
    ServiceLocator::get(CommentService::class)->validateUserComment($comment_id);
}

function invalidate_user_cache_nb_comments(): void
{
    ServiceLocator::get(CommentService::class)->invalidateUserCacheNbComments();
}

// ── Notification delegates (inlined from functions_notification.inc.php) ──

function get_std_sql_where_restrict_filter(string $prefix_condition, string $img_field = 'ic.image_id', bool $force_one_condition = false): string
{
    return ServiceLocator::get(NotificationService::class)->getStdSqlWhereRestrictFilter($prefix_condition, $img_field, $force_one_condition);
}

/** @return array<mixed>|int|null */
function custom_notification_query(string $action, string $type, ?string $start = null, ?string $end = null): array|int|null
{
    return ServiceLocator::get(NotificationService::class)->customNotificationQuery($action, $type, $start, $end);
}

function nb_new_comments(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewComments($start, $end);
}

/** @return array<mixed> */
function new_comments(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newComments($start, $end);
}

function nb_unvalidated_comments(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbUnvalidatedComments($start, $end);
}

function nb_new_elements(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewElements($start, $end);
}

/** @return array<mixed> */
function new_elements(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newElements($start, $end);
}

function nb_updated_categories(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbUpdatedCategories($start, $end);
}

/** @return array<mixed> */
function updated_categories(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->updatedCategories($start, $end);
}

function nb_new_users(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewUsers($start, $end);
}

/** @return array<mixed> */
function new_users(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newUsers($start, $end);
}

function news_exists(?string $start = null, ?string $end = null): bool
{
    return ServiceLocator::get(NotificationService::class)->newsExists($start, $end);
}

/** @param array<mixed> $news */
function add_news_line(array &$news, int $count, string $singular_key, string $plural_key, ?string $url = '', bool $add_url = false): void
{
    ServiceLocator::get(NotificationService::class)->addNewsLine($news, $count, $singular_key, $plural_key, $url, $add_url);
}

/** @return string[] */
function news(?string $start = null, ?string $end = null, bool $exclude_img_cats = false, bool $add_url = false, ?string $auth_key = null): array
{
    return ServiceLocator::get(NotificationService::class)->news($start, $end, $exclude_img_cats, $add_url, $auth_key);
}

/** @return array<mixed>|null */
function get_recent_post_dates(int $max_dates, int $max_elements, int $max_cats): ?array
{
    return ServiceLocator::get(NotificationService::class)->getRecentPostDates($max_dates, $max_elements, $max_cats);
}

/**
 * @param array<mixed> $args
 * @return array<mixed>
 */
function get_recent_post_dates_array(array $args): array
{
    return ServiceLocator::get(NotificationService::class)->getRecentPostDatesArray($args);
}

/** @param array<mixed> $date_detail */
function get_html_description_recent_post_date(array $date_detail, ?string $auth_key = null): string
{
    return ServiceLocator::get(NotificationService::class)->getHtmlDescriptionRecentPostDate($date_detail, $auth_key);
}

/** @param array<mixed> $date_detail */
function get_title_recent_post_date(array $date_detail): string
{
    return ServiceLocator::get(NotificationService::class)->getTitleRecentPostDate($date_detail);
}

// ── Mail delegates (inlined from functions_mail.inc.php) ──────────────────

function get_mail_sender_name(): string
{
    return ServiceLocator::get(MailService::class)->getMailSenderName();
}

function get_mail_sender_email(): string
{
    return ServiceLocator::get(MailService::class)->getMailSenderEmail();
}

/** @return array<string,mixed> */
function get_mail_configuration(): array
{
    return ServiceLocator::get(MailService::class)->getMailConfiguration();
}

function format_email(string $name, string $email): string
{
    return ServiceLocator::get(MailService::class)->formatEmail($name, $email);
}

/**
 * @param string|array<mixed> $input
 * @return array{email: string, name: string}
 */
function unformat_email(string|array $input): array
{
    return ServiceLocator::get(MailService::class)->unformatEmail($input);
}

/** @return string[][] */
function get_clean_recipients_list(mixed $data): array
{
    return ServiceLocator::get(MailService::class)->getCleanRecipientsList($data);
}

#[\Deprecated(message: '2.6')]
function get_strict_email_list(string $email_list): string
{
    return ServiceLocator::get(MailService::class)->getStrictEmailList($email_list);
}

function &get_mail_template(string $email_format): Template
{
    $result = ServiceLocator::get(MailService::class)->getMailTemplate($email_format);
    return $result;
}

function get_str_email_format(bool $is_html): string
{
    return ServiceLocator::get(MailService::class)->getStrEmailFormat($is_html);
}

function switch_lang_to(string $language): void
{
    ServiceLocator::get(MailService::class)->switchLangTo($language);
}

function switch_lang_back(): void
{
    ServiceLocator::get(MailService::class)->switchLangBack();
}

/**
 * @param array<mixed>|string $subject
 * @param array<mixed>|string $content
 */
function pwg_mail_notification_admins(array|string $subject, array|string $content, bool $send_technical_details = true, ?int $group_id = null): bool
{
    return ServiceLocator::get(MailService::class)->pwgMailNotificationAdmins($subject, $content, $send_technical_details, $group_id);
}

/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_admins(array $args = [], array $tpl = [], bool $exclude_current_user = true, bool $only_webmasters = false, ?int $group_id = null): bool
{
    return ServiceLocator::get(MailService::class)->pwgMailAdmins($args, $tpl, $exclude_current_user, $only_webmasters, $group_id);
}

/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_group(int $group_id, array $args = [], array $tpl = []): bool|int
{
    return ServiceLocator::get(MailService::class)->pwgMailGroup($group_id, $args, $tpl);
}

function mail_function_is_usable(): bool
{
    return ServiceLocator::get(MailService::class)->mailFunctionIsUsable();
}

/**
 * @param string|array<mixed> $to
 * @param array<mixed>        $args
 * @param array<mixed>        $tpl
 */
function pwg_mail(string|array $to, array $args = [], array $tpl = []): bool
{
    return ServiceLocator::get(MailService::class)->pwgMail($to, $args, $tpl);
}

#[\Deprecated(message: '2.6')]
function pwg_send_mail(mixed $result, string $to, string $subject, string $content, string $headers): bool|int
{
    return ServiceLocator::get(MailService::class)->pwgSendMail($result, $to, $subject, $content, $headers);
}

function move_css_to_body(string $content): string
{
    return ServiceLocator::get(MailService::class)->moveCssToBody($content);
}

/** @param array<mixed> $args */
function pwg_send_mail_test(bool $success, mixed $mail, array $args): void
{
    ServiceLocator::get(MailService::class)->pwgSendMailTest($success, $mail, $args);
}

/** @return array<mixed> */
function pwg_generate_reset_password_mail(string $username, string $password_link, string $gallery_title, string $remaining_time): array
{
    return ServiceLocator::get(MailService::class)->pwgGenerateResetPasswordMail($username, $password_link, $gallery_title, $remaining_time);
}

/** @return array<mixed> */
function pwg_generate_set_password_mail(string $username, string $set_password_link, string $gallery_title, string $remaining_time): array
{
    return ServiceLocator::get(MailService::class)->pwgGenerateSetPasswordMail($username, $set_password_link, $gallery_title, $remaining_time);
}

/** @return array<mixed> */
function pwg_generate_code_verification_mail(string $code): array
{
    return ServiceLocator::get(MailService::class)->pwgGenerateCodeVerificationMail($code);
}

/** @return array<mixed> */
function pwg_generate_success_reset_password_mail(string $username, int $nb_of_apikeys): array
{
    return ServiceLocator::get(MailService::class)->pwgGenerateSuccessResetPasswordMail($username, $nb_of_apikeys);
}

trigger_notify('functions_mail_included');
