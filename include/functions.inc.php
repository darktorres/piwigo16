<?php

declare(strict_types=1);

use Piwigo\Config\ConfigService;
use Piwigo\Core\DateService;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\QueryHelper;
use Piwigo\Lang\LangService;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                        |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once(PHPWG_ROOT_PATH . 'include/functions_plugins.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_user.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_cookie.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_session.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_category.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_html.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_tag.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/functions_url.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/derivative_params.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
include_once(PHPWG_ROOT_PATH . 'include/derivative.inc.php');

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
    if (\Piwigo\Core\ServiceLocator::has(\Piwigo\Language\LanguageRepository::class)) {
        $languages = [];
        foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Language\LanguageRepository::class)->findIdNameMap() as $id => $name) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }
    $languages = [];
    foreach (\Piwigo\Db\DbConnection::build()
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
    if (!\Piwigo\Core\LanguageStack::initialized() || !\Piwigo\Template\TemplateRegistry::isInitialized()) {
        \Piwigo\Users\CurrentUser::setRawAttributes(build_user(\Piwigo\Config\Config::guestId(), true));
        load_language('common.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
        $tpl = new \Piwigo\Template\Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
        \Piwigo\Template\TemplateRegistry::set($tpl);
    } elseif (defined('IN_ADMIN') && IN_ADMIN) {
        $tpl = new \Piwigo\Template\Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
        \Piwigo\Template\TemplateRegistry::set($tpl);
    }

    if (empty($msg)) {
        $msg = nl2br(l10n('Redirection...'));
    }

    $refresh  = is_numeric($refresh_time) ? (int) $refresh_time : 0;
    $url_link = is_scalar($url) ? (string) $url : '';
    $title    = 'redirection';

    $tpl = \Piwigo\Template\TemplateRegistry::current();
    $tpl->set_filenames(['redirect' => 'redirect.tpl']);

    include(PHPWG_ROOT_PATH . 'include/page_header.php');

    $tpl->set_filenames(['redirect' => 'redirect.tpl']);
    $tpl->assign('REDIRECT_MSG', $msg);
    $tpl->parse('redirect');

    include(PHPWG_ROOT_PATH . 'include/page_tail.php');
    exit();
}

function redirect(mixed $url, mixed $msg = '', mixed $refresh_time = 0): void
{
    $urlStr      = is_scalar($url) ? (string) $url : '';
    $msgStr      = is_scalar($msg) ? (string) $msg : '';
    $refreshTime = is_numeric($refresh_time) ? (int) $refresh_time : 0;
    if (\Piwigo\Config\Config::defaultRedirectMethod() === 'http' && $refreshTime === 0 && !headers_sent()) {
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
    return \Piwigo\Core\Lang::t($key ?? '', ...$args);
}

function l10n_dec(string $singular_key, string $plural_key, int|float|null $decimal): string
{
    return \Piwigo\Lang\Translator::get()->plural($singular_key, $plural_key, (int) $decimal);
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

    if (\Piwigo\Core\ServiceLocator::has(\Doctrine\DBAL\Connection::class)) {
        $conn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    } else {
        $conn = \Piwigo\Db\DbConnection::build();
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
        \Piwigo\Config\Config::override(is_scalar($row['param']) ? (string) $row['param'] : '', $val);
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
    ServiceLocator::get(ConfigService::class)->confUpdateParam($param, $value, $updateGlobal, $parser);
}

/** @param string|string[] $params */
function conf_delete_param(string|array $params): void
{
    ServiceLocator::get(ConfigService::class)->confDeleteParam($params);
}

function conf_get_param(mixed $param, mixed $default_value = null): mixed
{
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
        $info   = \Piwigo\Core\LanguageStack::info();
        $parent = $info['parent'] ?? null;
        return is_string($parent) && $parent !== '' ? $parent : null;
    }
    $f = PHPWG_ROOT_PATH . 'language/' . (is_scalar($lang_id) ? (string) $lang_id : '') . '/common.lang.php';
    if (file_exists($f)) {
        include($f);
        /** @var array<string,mixed> $lang_info */
        return !empty($lang_info['parent']) && is_string($lang_info['parent']) ? $lang_info['parent'] : null;
    }
    return null;
}

/** @param array<mixed> $options */
function load_language(string $filename, string $dirname = '', array $options = []): string|bool
{
    $user = \Piwigo\Users\CurrentUser::isInitialized()
        ? \Piwigo\Users\CurrentUser::get()->rawAttributes
        : (is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : []);

    if (!empty($dirname) && !empty($filename) && empty($options['return'])
        && !\Piwigo\Core\LanguageStack::hasPluginFile($dirname, $filename)) {
        \Piwigo\Core\LanguageStack::trackPluginFile($dirname, $filename, $options);
    }

    if (empty($dirname)) {
        $dirname = PHPWG_ROOT_PATH;
    }
    $langDir = $dirname . 'language/';

    $default_language = (\Piwigo\Core\InstallSentinel::isInstalled() && !defined('UPGRADES_PATH'))
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
    $languages_typed = array_values(array_unique(array_filter($languages, 'is_string')));

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
                \Piwigo\Core\LanguageStack::mergeLang((array) $lang);
                \Piwigo\Core\LanguageStack::mergeInfo((array) $lang_info);
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

    \Piwigo\Lang\Translator::get()->load($selected_language, $po_file);

    $poHeaders    = (new \Gettext\Loader\PoLoader())->loadFile($po_file)->getHeaders();
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
    \Piwigo\Core\LanguageStack::mergeInfo($lang_info_po);

    return true;
}

function convert_charset(string $str, string $source_charset, string $dest_charset): string
{
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
