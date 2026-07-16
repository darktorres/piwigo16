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
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Logger;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Lang\Translator;
use Piwigo\Page\PaginationService;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Validation\InputValidator;

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

/**
 * returns the current microsecond since Unix epoch
 */
function micro_seconds(): string
{
    $t1 = explode(' ', microtime());
    $t2 = explode('.', $t1[0]);
    $t2 = $t1[1] . substr($t2[1], 0, 6);
    return $t2;
}

/**
 * returns a float value coresponding to the number of seconds since
 * the unix epoch (1st January 1970) and the microseconds are precised
 * e.g. 1052343429.89276600
 */
function get_moment(): float
{
    return microtime(true);
}

/**
 * returns the number of seconds (with 3 decimals precision)
 * between the start time and the end time given
 *
 * @param float $start
 * @param float $end
 * @return string "$TIME s"
 */
function get_elapsed_time($start, $end): string
{
    return number_format($end - $start, 3, '.', ' ') . ' s';
}

/**
 * returns the part of the string after the last "." (empty string if none)
 */
function get_extension(?string $filename): string
{
    if ($filename === null) {
        return '';
    }

    $dot_position = strrpos($filename, '.');

    return $dot_position === false ? '' : substr($filename, $dot_position + 1);
}

/**
 * returns the part of the string before the last ".".
 * get_filename_wo_extension( 'test.tar.gz' ) = 'test.tar'
 *
 * @param string $filename
 * @return string
 */
function get_filename_wo_extension($filename)
{
    $pos = strrpos($filename, '.');
    return ($pos === false) ? $filename : substr($filename, 0, $pos);
}

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
 * creates directory if not exists and ensures that directory is writable
 *
 * @param string $dir
 * @param int $flags combination of MKGETDIR_xxx
 */
function mkgetdir($dir, $flags = MKGETDIR_DEFAULT): bool
{
    if (! is_dir($dir)) {
        /** @var array<string, mixed> $conf */
        global $conf;
        if (str_starts_with(PHP_OS, 'WIN')) {
            $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
        }
        $umask = umask(0);
        $chmod_value = $conf['chmod_value'];
        $chmod_value = is_numeric($chmod_value) ? (int) $chmod_value : 0755;
        $mkd = @mkdir($dir, $chmod_value, ((bool) ($flags & MKGETDIR_RECURSIVE)) ? true : false);
        umask($umask);
        if ($mkd == false) {
            ! (bool) ($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("{$dir} " . l10n('no write access'));
            return false;
        }
        if ((bool) ($flags & MKGETDIR_PROTECT_HTACCESS)) {
            $file = $dir . '/.htaccess';
            file_exists($file) or (bool) @file_put_contents($file, 'deny from all');
        }
        if ((bool) ($flags & MKGETDIR_PROTECT_INDEX)) {
            $file = $dir . '/index.htm';
            file_exists($file) or (bool) @file_put_contents($file, 'Not allowed!');
        }
    }
    if (! is_writable($dir)) {
        ! (bool) ($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("{$dir} " . l10n('no write access'));
        return false;
    }
    return true;
}

/**
 * finds out if a string is in ASCII, UTF-8 or other encoding
 *
 * @return int *0* if _$str_ is ASCII, *1* if UTF-8, *-1* otherwise
 */
function qualify_utf8(string $Str): int
{
    $ret = 0;
    for ($i = 0; $i < strlen($Str); $i++) {
        if (ord($Str[$i]) < 0x80) {
            continue;
        } # 0bbbbbbb
        $ret = 1;
        if ((ord($Str[$i]) & 0xE0) == 0xC0) {
            $n = 1;
        } # 110bbbbb
        elseif ((ord($Str[$i]) & 0xF0) == 0xE0) {
            $n = 2;
        } # 1110bbbb
        elseif ((ord($Str[$i]) & 0xF8) == 0xF0) {
            $n = 3;
        } # 11110bbb
        elseif ((ord($Str[$i]) & 0xFC) == 0xF8) {
            $n = 4;
        } # 111110bb
        elseif ((ord($Str[$i]) & 0xFE) == 0xFC) {
            $n = 5;
        } # 1111110b
        else {
            return -1;
        } # Does not match any model
        for ($j = 0; $j < $n; $j++) { # n bytes matching 10bbbbbb follow ?
            if ((++$i == strlen($Str)) || ((ord($Str[$i]) & 0xC0) != 0x80)) {
                return -1;
            }
        }
    }
    return $ret;
}

/**
 * Remove accents from a UTF-8 or ISO-8859-1 string (from wordpress)
 *
 * @param string $string
 * @return string
 */
function remove_accents($string)
{
    $utf = qualify_utf8($string);
    if ($utf == 0) {
        return $string; // ascii
    }

    if ($utf > 0) {
        $chars = [
            // Decompositions for Latin-1 Supplement
            "\xc3\x80" => 'A',
            "\xc3\x81" => 'A',
            "\xc3\x82" => 'A',
            "\xc3\x83" => 'A',
            "\xc3\x84" => 'A',
            "\xc3\x85" => 'A',
            "\xc3\x87" => 'C',
            "\xc3\x88" => 'E',
            "\xc3\x89" => 'E',
            "\xc3\x8a" => 'E',
            "\xc3\x8b" => 'E',
            "\xc3\x8c" => 'I',
            "\xc3\x8d" => 'I',
            "\xc3\x8e" => 'I',
            "\xc3\x8f" => 'I',
            "\xc3\x91" => 'N',
            "\xc3\x92" => 'O',
            "\xc3\x93" => 'O',
            "\xc3\x94" => 'O',
            "\xc3\x95" => 'O',
            "\xc3\x96" => 'O',
            "\xc3\x99" => 'U',
            "\xc3\x9a" => 'U',
            "\xc3\x9b" => 'U',
            "\xc3\x9c" => 'U',
            "\xc3\x9d" => 'Y',
            "\xc3\x9f" => 's',
            "\xc3\xa0" => 'a',
            "\xc3\xa1" => 'a',
            "\xc3\xa2" => 'a',
            "\xc3\xa3" => 'a',
            "\xc3\xa4" => 'a',
            "\xc3\xa5" => 'a',
            "\xc3\xa7" => 'c',
            "\xc3\xa8" => 'e',
            "\xc3\xa9" => 'e',
            "\xc3\xaa" => 'e',
            "\xc3\xab" => 'e',
            "\xc3\xac" => 'i',
            "\xc3\xad" => 'i',
            "\xc3\xae" => 'i',
            "\xc3\xaf" => 'i',
            "\xc3\xb1" => 'n',
            "\xc3\xb2" => 'o',
            "\xc3\xb3" => 'o',
            "\xc3\xb4" => 'o',
            "\xc3\xb5" => 'o',
            "\xc3\xb6" => 'o',
            "\xc3\xb9" => 'u',
            "\xc3\xba" => 'u',
            "\xc3\xbb" => 'u',
            "\xc3\xbc" => 'u',
            "\xc3\xbd" => 'y',
            "\xc3\xbf" => 'y',
            // Decompositions for Latin Extended-A
            "\xc4\x80" => 'A',
            "\xc4\x81" => 'a',
            "\xc4\x82" => 'A',
            "\xc4\x83" => 'a',
            "\xc4\x84" => 'A',
            "\xc4\x85" => 'a',
            "\xc4\x86" => 'C',
            "\xc4\x87" => 'c',
            "\xc4\x88" => 'C',
            "\xc4\x89" => 'c',
            "\xc4\x8a" => 'C',
            "\xc4\x8b" => 'c',
            "\xc4\x8c" => 'C',
            "\xc4\x8d" => 'c',
            "\xc4\x8e" => 'D',
            "\xc4\x8f" => 'd',
            "\xc4\x90" => 'D',
            "\xc4\x91" => 'd',
            "\xc4\x92" => 'E',
            "\xc4\x93" => 'e',
            "\xc4\x94" => 'E',
            "\xc4\x95" => 'e',
            "\xc4\x96" => 'E',
            "\xc4\x97" => 'e',
            "\xc4\x98" => 'E',
            "\xc4\x99" => 'e',
            "\xc4\x9a" => 'E',
            "\xc4\x9b" => 'e',
            "\xc4\x9c" => 'G',
            "\xc4\x9d" => 'g',
            "\xc4\x9e" => 'G',
            "\xc4\x9f" => 'g',
            "\xc4\xa0" => 'G',
            "\xc4\xa1" => 'g',
            "\xc4\xa2" => 'G',
            "\xc4\xa3" => 'g',
            "\xc4\xa4" => 'H',
            "\xc4\xa5" => 'h',
            "\xc4\xa6" => 'H',
            "\xc4\xa7" => 'h',
            "\xc4\xa8" => 'I',
            "\xc4\xa9" => 'i',
            "\xc4\xaa" => 'I',
            "\xc4\xab" => 'i',
            "\xc4\xac" => 'I',
            "\xc4\xad" => 'i',
            "\xc4\xae" => 'I',
            "\xc4\xaf" => 'i',
            "\xc4\xb0" => 'I',
            "\xc4\xb1" => 'i',
            "\xc4\xb2" => 'IJ',
            "\xc4\xb3" => 'ij',
            "\xc4\xb4" => 'J',
            "\xc4\xb5" => 'j',
            "\xc4\xb6" => 'K',
            "\xc4\xb7" => 'k',
            "\xc4\xb8" => 'k',
            "\xc4\xb9" => 'L',
            "\xc4\xba" => 'l',
            "\xc4\xbb" => 'L',
            "\xc4\xbc" => 'l',
            "\xc4\xbd" => 'L',
            "\xc4\xbe" => 'l',
            "\xc4\xbf" => 'L',
            "\xc5\x80" => 'l',
            "\xc5\x81" => 'L',
            "\xc5\x82" => 'l',
            "\xc5\x83" => 'N',
            "\xc5\x84" => 'n',
            "\xc5\x85" => 'N',
            "\xc5\x86" => 'n',
            "\xc5\x87" => 'N',
            "\xc5\x88" => 'n',
            "\xc5\x89" => 'N',
            "\xc5\x8a" => 'n',
            "\xc5\x8b" => 'N',
            "\xc5\x8c" => 'O',
            "\xc5\x8d" => 'o',
            "\xc5\x8e" => 'O',
            "\xc5\x8f" => 'o',
            "\xc5\x90" => 'O',
            "\xc5\x91" => 'o',
            "\xc5\x92" => 'OE',
            "\xc5\x93" => 'oe',
            "\xc5\x94" => 'R',
            "\xc5\x95" => 'r',
            "\xc5\x96" => 'R',
            "\xc5\x97" => 'r',
            "\xc5\x98" => 'R',
            "\xc5\x99" => 'r',
            "\xc5\x9a" => 'S',
            "\xc5\x9b" => 's',
            "\xc5\x9c" => 'S',
            "\xc5\x9d" => 's',
            "\xc5\x9e" => 'S',
            "\xc5\x9f" => 's',
            "\xc5\xa0" => 'S',
            "\xc5\xa1" => 's',
            "\xc5\xa2" => 'T',
            "\xc5\xa3" => 't',
            "\xc5\xa4" => 'T',
            "\xc5\xa5" => 't',
            "\xc5\xa6" => 'T',
            "\xc5\xa7" => 't',
            "\xc5\xa8" => 'U',
            "\xc5\xa9" => 'u',
            "\xc5\xaa" => 'U',
            "\xc5\xab" => 'u',
            "\xc5\xac" => 'U',
            "\xc5\xad" => 'u',
            "\xc5\xae" => 'U',
            "\xc5\xaf" => 'u',
            "\xc5\xb0" => 'U',
            "\xc5\xb1" => 'u',
            "\xc5\xb2" => 'U',
            "\xc5\xb3" => 'u',
            "\xc5\xb4" => 'W',
            "\xc5\xb5" => 'w',
            "\xc5\xb6" => 'Y',
            "\xc5\xb7" => 'y',
            "\xc5\xb8" => 'Y',
            "\xc5\xb9" => 'Z',
            "\xc5\xba" => 'z',
            "\xc5\xbb" => 'Z',
            "\xc5\xbc" => 'z',
            "\xc5\xbd" => 'Z',
            "\xc5\xbe" => 'z',
            "\xc5\xbf" => 's',
            // Decompositions for Latin Extended-B
            "\xc8\x98" => 'S',
            "\xc8\x99" => 's',
            "\xc8\x9a" => 'T',
            "\xc8\x9b" => 't',
            // Euro Sign
            "\xe2\x82\xac" => 'E',
            // GBP (Pound) Sign
            "\xc2\xa3" => '',
        ];

        $string = strtr($string, $chars);
    } else {
        // Assume ISO-8859-1 if not UTF-8
        $chars = [];
        $chars['in'] = chr(128) . chr(131) . chr(138) . chr(142) . chr(154) . chr(158)
          . chr(159) . chr(162) . chr(165) . chr(181) . chr(192) . chr(193) . chr(194)
          . chr(195) . chr(196) . chr(197) . chr(199) . chr(200) . chr(201) . chr(202)
          . chr(203) . chr(204) . chr(205) . chr(206) . chr(207) . chr(209) . chr(210)
          . chr(211) . chr(212) . chr(213) . chr(214) . chr(216) . chr(217) . chr(218)
          . chr(219) . chr(220) . chr(221) . chr(224) . chr(225) . chr(226) . chr(227)
          . chr(228) . chr(229) . chr(231) . chr(232) . chr(233) . chr(234) . chr(235)
          . chr(236) . chr(237) . chr(238) . chr(239) . chr(241) . chr(242) . chr(243)
          . chr(244) . chr(245) . chr(246) . chr(248) . chr(249) . chr(250) . chr(251)
          . chr(252) . chr(253) . chr(255);

        $chars['out'] = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';

        $string = strtr($string, $chars['in'], $chars['out']);
        $double_chars = [];
        $double_chars['in'] = [chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254)];
        $double_chars['out'] = ['OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th'];
        $string = str_replace($double_chars['in'], $double_chars['out'], $string);
    }

    return $string;
}

if (function_exists('mb_strtolower') && defined('PWG_CHARSET')) {
    /**
     * removes accents from a string and converts it to lower case
     *
     * @param string $term
     * @return string
     */
    function pwg_transliterate($term)
    {
        return remove_accents(mb_strtolower($term, PWG_CHARSET));
    }
} else {
    /**
     * @ignore
     * @param string $term
     */
    function pwg_transliterate($term): string
    {
        return remove_accents(strtolower($term));
    }
}

/**
 * simplify a string to insert it into an URL
 *
 * @param string $str
 */
function str2url($str): string
{
    $str = $safe = pwg_transliterate($str);
    $str = preg_replace('/[^\x80-\xffa-z0-9_\s\'\:\/\[\],-]/', '', $str);
    $str = preg_replace('/[\s\'\:\/\[\],-]+/', ' ', trim((string) $str));
    assert($str !== null);
    $res = str_replace(' ', '_', $str);

    if (empty($res)) {
        $res = str_replace(' ', '_', $safe);
    }

    return $res;
}

/**
 * returns an array with a list of {language_code => language_name}
 *
 * @return string[]
 */
function get_languages(): array
{
    $query = '
SELECT id, name
  FROM ' . Tables::languages() . '
  ORDER BY name ASC
;';
    $result = pwg_query($query);

    $languages = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $id = $row['id'];
        $name = $row['name'];
        if (! is_string($id) || ! is_string($name)) {
            continue;
        }

        if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
            $languages[$id] = $name;
        }
    }

    return $languages;
}

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
        $cached_sections = is_string($cached_sections) || is_array($cached_sections) ? safe_unserialize($cached_sections) : null;
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
 * @param int|string|array<int, int|string> $object_id
 * @param array<string, mixed> $details
 */
function pwg_activity(string $object, $object_id, string $action, array $details = []): void
{
    new ActivityService(new ActivityRepository(DbConnection::build()))
        ->record($object, $object_id, $action, $details);
}

/**
 * Computes the difference between two dates.
 *
 * @param DateTime $date1
 * @param DateTime $date2
 * @return DateInterval
 */
function dateDiff($date1, $date2)
{
    return $date1->diff($date2);
}

/**
 * converts a string into a DateTime object
 *
 * @param int|string|DateTime|false $original timestamp, datetime string, or
 *   an already-converted DateTime (returned as-is; some callers pass the
 *   same value through this function repeatedly) — false/empty short-
 *   circuits to the empty() check below, so callers may pass another
 *   function's own DateTime|false return straight through
 * @param string $format input format respecting date() syntax
 * @return DateTime|false
 */
function str2DateTime($original, $format = null)
{
    if (empty($original)) {
        return false;
    }

    if ($original instanceof DateTime) {
        return $original;
    }

    if (! empty($format)) {// from known date format
        return DateTime::createFromFormat('!' . $format, (string) $original); // ! char to reset fields to UNIX epoch
    } else {
        $t = trim((string) $original, '0123456789');
        if (empty($t)) { // from timestamp
            return new DateTime('@' . $original);
        } else { // from unknown date format (assuming something like Y-m-d H:i:s)
            $ymdhms = [];
            $tok = strtok((string) $original, '- :/');
            while ($tok !== false) {
                $ymdhms[] = $tok;
                $tok = strtok('- :/');
            }

            if (count($ymdhms) < 3) {
                return false;
            }
            if (! isset($ymdhms[3])) {
                $ymdhms[3] = 0;
            }
            if (! isset($ymdhms[4])) {
                $ymdhms[4] = 0;
            }
            if (! isset($ymdhms[5])) {
                $ymdhms[5] = 0;
            }

            $date = new DateTime();
            $date->setDate((int) $ymdhms[0], (int) $ymdhms[1], (int) $ymdhms[2]);
            $date->setTime((int) $ymdhms[3], (int) $ymdhms[4], (int) $ymdhms[5]);
            return $date;
        }
    }
}

/**
 * returns a formatted and localized date for display (LEGACY use format_date)
 *
 * @param int|string|DateTime|false $original timestamp, datetime string, or
 *   an already-converted DateTime/false — same as format_date(), since this
 *   re-derives via the same permissive str2DateTime()
 * @param string[]|null $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
 * @param string $format input format respecting date() syntax
 * @return string
 */
function format_date_legacy($original, $show = null, $format = null)
{
    /** @var array<string, mixed> $lang */
    global $lang;

    $date = str2DateTime($original, $format);

    if (! (bool) $date) {
        return l10n('N/A');
    }

    if ($show === null) {
        $show = ['day_name', 'day', 'month', 'year'];
    }

    $day_names = $lang['day'] ?? [];
    $day_names = is_array($day_names) ? $day_names : [];
    $month_names = $lang['month'] ?? [];
    $month_names = is_array($month_names) ? $month_names : [];

    $print = '';
    if (in_array('day_name', $show)) {
        $day_name = $day_names[$date->format('w')] ?? '';
        $print .= (is_string($day_name) ? $day_name : '') . ' ';
    }

    if (in_array('day', $show)) {
        $print .= $date->format('j') . ' ';
    }

    if (in_array('month', $show)) {
        $month_name = $month_names[$date->format('n')] ?? '';
        $print .= (is_string($month_name) ? $month_name : '') . ' ';
    }

    if (in_array('year', $show)) {
        $print .= $date->format('Y') . ' ';
    }

    if (in_array('time', $show)) {
        $temp = $date->format('H:i');
        if ($temp != '00:00') {
            $print .= $temp . ' ';
        }
    }

    return trim($print);
}

/**
 * returns a formatted and localized date for display
 *
 * @param int|string|DateTime|false $original timestamp, datetime string, or
 *   an already-converted DateTime/false — format_fromto() passes
 *   str2DateTime()'s own return type straight through; both are handled
 *   gracefully (DateTime passes through str2DateTime() as-is, false/empty
 *   short-circuits to the "N/A" string below)
 * @param string[]|null $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
 *    THIS PARAMETER IS PLANNED TO CHANGE
 * @param string $format input format respecting date() syntax
 * @return string
 * @since 16
 */
function format_date($original, $show = null, $format = null)
{
    /** @var array<string, mixed> $user */
    global $user;

    $date = str2DateTime($original, $format);

    if (! (bool) $date) {
        return l10n('N/A');
    }

    if ($show === null) {
        $show = ['day_name', 'day', 'month', 'year'];
    }

    // use IntlDateFormatter for proper i18n need pkg php-intl
    if (class_exists('IntlDateFormatter')
      and in_array('year', $show)
      and in_array('month', $show)
    ) {
        $timeType = in_array('time', $show) ? IntlDateFormatter::MEDIUM : IntlDateFormatter::NONE;
        $dateType = IntlDateFormatter::FULL;

        if (! in_array('day_name', $show)) {
            $dateType = IntlDateFormatter::LONG;
        }

        $user_language = $user['language'] ?? null;
        $user_language = is_string($user_language) ? $user_language : null;
        $fmt = new IntlDateFormatter($user_language, $dateType, $timeType);
        $formatted = $fmt->format($date);
        if ($formatted !== false) {
            return $formatted;
        }
    }

    return format_date_legacy($original, $show, $format);
}

/**
 * Format a "From ... to ..." string from two dates
 * @param string $from
 * @param string $to
 * @param bool $full
 * @return string
 */
function format_fromto($from, $to, $full = false)
{
    $from = str2DateTime($from);
    $to = str2DateTime($to);

    if ($from === false || $to === false) {
        return l10n('N/A');
    }

    if ($from->format('Y-m-d') == $to->format('Y-m-d')) {
        return format_date($from);
    } else {
        if ($full || $from->format('Y') != $to->format('Y')) {
            $from_str = format_date($from);
        } elseif ($from->format('m') != $to->format('m')) {
            $from_str = format_date($from, ['day_name', 'day', 'month']);
        } else {
            $from_str = format_date($from, ['day_name', 'day']);
        }
        $to_str = format_date($to);

        return l10n('from %s to %s', $from_str, $to_str);
    }
}

/**
 * Works out the time since the given date
 *
 * @param int|string $original timestamp or datetime string
 * @param string $stop year,month,week,day,hour,minute,second
 * @param string $format input format respecting date() syntax
 * @param bool $with_text append "ago" or "in the future"
 * @return string
 */
function time_since($original, $stop = 'minute', $format = null, $with_text = true, bool $with_week = true, bool $only_last_unit = false)
{
    $date = str2DateTime($original, $format);

    if (! (bool) $date) {
        return l10n('N/A');
    }

    $now = pwg_now();
    $diff = dateDiff($now, $date);

    $chunks = [
        'year' => $diff->y,
        'month' => $diff->m,
        'week' => 0,
        'day' => $diff->d,
        'hour' => $diff->h,
        'minute' => $diff->i,
        'second' => $diff->s,
    ];

    // DateInterval does not contain the number of weeks
    if ($with_week) {
        $chunks['week'] = (int) floor($chunks['day'] / 7);
        $chunks['day'] = $chunks['day'] - $chunks['week'] * 7;
    }

    $j = array_search($stop, array_keys($chunks));

    $print = '';
    $i = 0;

    if (! $only_last_unit) {
        foreach ($chunks as $name => $value) {
            if ($value != 0) {
                $print .= ' ' . l10n_dec('%d ' . $name, '%d ' . $name . 's', $value);
            }
            if (! empty($print) && $i >= $j) {
                break;
            }
            $i++;
        }
    } else {
        $reversed_chunks_names = array_keys($chunks);
        while ($print == '' && $i < count($reversed_chunks_names)) {
            $name = $reversed_chunks_names[$i];
            $value = $chunks[$name];
            if ($value != 0) {
                $print = l10n_dec('%d ' . $name, '%d ' . $name . 's', $value);
            }
            if (! empty($print) && $i >= $j) {
                break;
            }
            $i++;
        }
    }

    $print = trim($print);

    if ($with_text) {
        // $diff->invert is 0 both when $date is strictly after $now AND
        // when $date == $now exactly (PHP's own DateInterval semantics: a
        // zero-length interval isn't "negative") -- comparing the DateTime
        // objects directly instead of trusting $diff->invert avoids
        // misreporting an exact-equality result ("0 seconds ago") as "in
        // the future". Real-clock callers essentially never hit this exact
        // tie; a frozen pwg_now() test clock hits it whenever the compared
        // timestamp was itself written via pwg_now().
        if ($now >= $date) {
            $print = l10n('%s ago', $print);
        } else {
            $print = l10n('%s in the future', $print);
        }
    }

    return $print;
}

/**
 * transform a date string from a format to another (MySQL to d/M/Y for instance)
 *
 * @param string $original
 * @param string $format_in respecting date() syntax
 * @param string $format_out respecting date() syntax
 * @param string|null $default returned as-is if _$original_ is empty or unparsable
 * @return string|null
 */
function transform_date($original, $format_in, $format_out, $default = null)
{
    if (empty($original)) {
        return $default;
    }
    $date = str2DateTime($original, $format_in);
    if ($date === false) {
        return $default;
    }
    return $date->format($format_out);
}

/**
 * append a variable to _$debug_ global
 *
 * @param string $string
 */
function pwg_debug($string): void
{
    /**
     * @var string $debug
     * @var float $t2
     * @var array<string, mixed> $page
     */
    global $debug,$t2,$page;

    $now = explode(' ', microtime());
    $now2 = explode('.', $now[0]);
    // microtime()'s own format ("<fraction> <seconds>", both always numeric)
    // guarantees this concatenation is always a numeric string.
    $now2_float = (float) ($now[1] . '.' . $now2[1]);
    $time = number_format($now2_float - $t2, 3, '.', ' ') . ' s';
    $debug .= '<p>';
    $debug .= '[' . $time . ', ';
    $count_queries = $page['count_queries'] ?? 0;
    $count_queries = is_numeric($count_queries) ? $count_queries : 0;
    $debug .= $count_queries . ' queries] : ' . $string;
    $debug .= "</p>\n";
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
        $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->buildUser($guest_id, true);
        load_language('common.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
            'no_fallback' => true,
            'local' => true,
        ]);
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultTheme());
    } elseif (defined('IN_ADMIN') and IN_ADMIN) {
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultTheme());
    }

    // Neither branch above runs when $template was already set and we're
    // not in admin -- it's the pre-existing bootstrap Template in that case,
    // but re-check for real since that isn't provable here statically.
    if (! ($template instanceof Template)) {
        $template = new Template(PHPWG_ROOT_PATH . 'themes', (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultTheme());
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
 * returns available themes
 *
 * @param bool $show_mobile
 * @return array<int|string, string>
 */
function get_pwg_themes($show_mobile = false): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $themes = [];

    $query = '
SELECT
    id,
    name
  FROM ' . Tables::themes() . '
  ORDER BY name ASC
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $id = $row['id'];
        $name = $row['name'];
        if (! is_string($id) || ! is_string($name)) {
            continue;
        }

        if ($id == $conf['mobile_theme']) {
            if (! $show_mobile) {
                continue;
            }
            $name .= ' (' . l10n('Mobile') . ')';
        }
        if (check_theme_installed($id)) {
            $themes[$id] = $name;
        }
    }

    // plugins want remove some themes based on user status maybe?
    $themes = trigger_change('get_pwg_themes', $themes);
    if (! is_array($themes)) {
        return [];
    }

    $filtered_themes = [];
    foreach ($themes as $key => $value) {
        if (is_string($value)) {
            $filtered_themes[$key] = $value;
        }
    }

    return $filtered_themes;
}

/**
 * check if a theme is installed (directory exsists)
 *
 * @param string $theme_id
 */
function check_theme_installed($theme_id): bool
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $themes_dir = $conf['themes_dir'];
    $themes_dir = is_string($themes_dir) ? $themes_dir : '';

    return file_exists($themes_dir . '/' . $theme_id . '/themeconf.inc.php');
}

/**
 * Transforms an original path to its pwg representative
 *
 * @param string $path
 * @param string $representative_ext
 */
function original_to_representative($path, $representative_ext): string
{
    $pos = strrpos($path, '/');
    $path = substr_replace($path, 'pwg_representative/', $pos + 1, 0);
    $pos = strrpos($path, '.');
    return substr_replace($path, $representative_ext, $pos + 1);
}

/**
 * Transforms an original path to its format
 *
 * @param string $path
 * @param string $format_ext
 */
function original_to_format($path, $format_ext): string
{
    $pos = strrpos($path, '/');
    $path = substr_replace($path, 'pwg_format/', $pos + 1, 0);
    $pos = strrpos($path, '.');
    return substr_replace($path, $format_ext, $pos + 1);
}

/**
 * get the full path of an image
 *
 * @param array<string, mixed> $element_info element information from db (at least 'path')
 */
function get_element_path(array $element_info): string
{
    $path = $element_info['path'];
    // images.path is `varchar(255) NOT NULL` in the schema — a genuine DB
    // row for this element always carries a string here.
    assert(is_string($path));

    if (! url_is_remote($path)) {
        $path = PHPWG_ROOT_PATH . $path;
    }
    return $path;
}

/**
 * fill the current user caddie with given elements, if not already in caddie
 *
 * @param array<int, int> $elements_id
 */
function fill_caddie($elements_id): void
{
    /** @var array<string, mixed> $user */
    global $user;

    $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

    new CaddieRepository(DbConnection::build())
        ->addElements($user_id, $elements_id);
}

/**
 * returns the element name from its filename.
 * removes file extension and replace underscores by spaces
 *
 * @param string $filename
 * @return string name
 */
function get_name_from_file($filename): string
{
    return str_replace('_', ' ', get_filename_wo_extension($filename));
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
 */
function get_webmaster_mail_address(): string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $user_fields = $conf['user_fields'] ?? [];
    $user_fields = is_array($user_fields) ? $user_fields : [];
    $email_field = $user_fields['email'] ?? 'email';
    $email_field = is_string($email_field) ? $email_field : 'email';
    $id_field = $user_fields['id'] ?? 'id';
    $id_field = is_string($id_field) ? $id_field : 'id';
    $webmaster_id = $conf['webmaster_id'] ?? 0;
    $webmaster_id = is_numeric($webmaster_id) ? (int) $webmaster_id : 0;

    $query = '
SELECT ' . $email_field . '
  FROM ' . Tables::users() . '
  WHERE ' . $id_field . ' = ' . $webmaster_id . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$email] = $row;
    // users.email is nullable — a webmaster without an email set is real,
    // not a bug, so this degrades to '' rather than asserting non-null.
    $email = is_string($email) ? $email : '';

    $email = trigger_change('get_webmaster_mail_address', $email);

    return is_string($email) ? $email : '';
}

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
 * Apply *unserialize* on a value only if it is a string
 * @since 2.7
 *
 * @param array<int|string, mixed>|string $value
 * @return mixed the unserialized value, false if $value is a malformed
 *   serialized string, or $value itself unchanged if it isn't a string
 */
function safe_unserialize($value)
{
    if (is_string($value)) {
        return unserialize($value);
    }
    return $value;
}

/**
 * Apply *json_decode* on a value only if it is a string
 * @since 2.7
 *
 * @param array<int|string, mixed>|string $value
 * @return array<int|string, mixed>
 */
function safe_json_decode($value)
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        // json_decode(..., true) returns null/scalar for malformed input or
        // a JSON scalar; this function's contract is always an array.
        return is_array($decoded) ? $decoded : [];
    }
    return $value;
}

/**
 * Prepends and appends strings at each value of the given array.
 *
 * @param array<int|string, mixed> $array
 * @param string $prepend_str
 * @param string $append_str
 * @return array<int|string, string>
 */
function prepend_append_array_items($array, $prepend_str, $append_str)
{
    array_walk($array, function (&$value, $key) use ($prepend_str, $append_str): void {
        $value = $prepend_str . (is_scalar($value) ? (string) $value : '') . $append_str;
    });
    return $array;
}

/**
 * creates an simple hashmap based on a SQL query.
 * choose one to be the key, another one to be the value.
 *
 * @return array<int|string, mixed>
 */
#[\Deprecated(message: '2.6')]
function simple_hash_from_query(string $query, ?string $keyname, ?string $valuename): array
{
    return query2array($query, $keyname, $valuename);
}

/**
 * creates an associative array based on a SQL query.
 * choose one to be the key
 *
 * @return array<int|string, mixed>
 */
#[\Deprecated(message: '2.6')]
function hash_from_query(string $query, ?string $keyname): array
{
    return query2array($query, $keyname);
}

/**
 * creates a numeric array based on a SQL query.
 * if _$fieldname_ is empty the returned value will be an array of arrays
 * if _$fieldname_ is provided the returned value will be a one dimension array
 *
 * @param string|false $fieldname
 * @return array<int|string, mixed>
 */
#[\Deprecated(message: '2.6')]
function array_from_query(string $query, $fieldname = false): array
{
    if ($fieldname === false) {
        return query2array($query);
    } else {
        return query2array($query, null, $fieldname);
    }
}

/**
 * Return the basename of the current script.
 * The lowercase case filename of the current script without extension
 */
function script_basename(): string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
        $raw = $_SERVER[$key] ?? null;
        if (is_string($raw) && $raw !== '') {
            $filename = strtolower($raw);
            if ((bool) $conf['php_extension_in_urls'] and get_extension($filename) !== 'php') {
                continue;
            }
            $basename = basename($filename, '.php');
            if (! empty($basename)) {
                return $basename;
            }
        }
    }
    return '';
}

/**
 * Return $conf['filter_pages'] value for the current page
 *
 * @param string $value_name
 * @return mixed
 */
function get_filter_page_value($value_name)
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $page_name = script_basename();

    $filter_pages = $conf['filter_pages'] ?? [];
    $filter_pages = is_array($filter_pages) ? $filter_pages : [];

    $page_filters = $filter_pages[$page_name] ?? null;
    if (is_array($page_filters) && isset($page_filters[$value_name])) {
        return $page_filters[$value_name];
    }
    $default_filters = $filter_pages['default'] ?? null;
    if (is_array($default_filters) && isset($default_filters[$value_name])) {
        return $default_filters[$value_name];
    } else {
        return null;
    }
}

/**
 * return the character set used by Piwigo
 */
function get_pwg_charset(): string
{
    $pwg_charset = 'utf-8';
    if (defined('PWG_CHARSET')) {
        $pwg_charset = PWG_CHARSET;
    }
    return $pwg_charset;
}

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
        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultLanguage() : AppInfo::DEFAULT_LANGUAGE;

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
 * converts a string from a character set to another character set
 *
 * @param string $str
 * @param string $source_charset
 * @param string $dest_charset
 */
function convert_charset($str, $source_charset, $dest_charset): string|false
{
    if ($source_charset == $dest_charset) {
        return $str;
    }
    if ($source_charset == 'iso-8859-1' and $dest_charset == 'utf-8') {
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
    if ($source_charset == 'utf-8' and $dest_charset == 'iso-8859-1') {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }
    if (function_exists('iconv')) {
        return iconv($source_charset, $dest_charset . '//TRANSLIT', $str);
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($str, $dest_charset, $source_charset);
    }
    return $str; // TODO
}

/**
 * makes sure a index.htm protects the directory from browser file listing
 *
 * @param string $dir
 */
function secure_directory($dir): void
{
    $file = $dir . '/index.htm';
    if (! file_exists($file)) {
        @file_put_contents($file, 'Not allowed!');
    }
}

/**
 * returns a "secret key" that is to be sent back when a user posts a form
 *
 * @param int $valid_after_seconds - key validity start time from now
 * @param string $aditionnal_data_to_hash
 */
function get_ephemeral_key($valid_after_seconds, $aditionnal_data_to_hash = ''): string
{
    return new EphemeralKeyService()
        ->generate($valid_after_seconds, $aditionnal_data_to_hash);
}

/**
 * verify a key sent back with a form
 *
 * @param string $key
 * @param string $aditionnal_data_to_hash
 */
function verify_ephemeral_key($key, $aditionnal_data_to_hash = ''): bool
{
    return new EphemeralKeyService()
        ->verify($key, $aditionnal_data_to_hash);
}

/**
 * return an array which will be sent to template to display navigation bar
 *
 * @param string $url base url of all links
 * @param int|string $nb_element may be a numeric string: comments.php,
 *   include/category_cats.inc.php, include/picture_comment.inc.php and
 *   admin/rating.php all pass a raw pwg_db_fetch_row()/FOUND_ROWS() value
 *   straight through, unlike the other call sites which pass a count()
 * @param int|string $start may be a numeric string: index.php/category_cats.inc.php
 *   pass a raw preg_match() capture (include/functions_url.inc.php), and
 *   admin/rating.php/admin/batch_manager.php pass $_GET/$_REQUEST directly
 *   after only an is_numeric() check
 * @param int $nb_element_page
 * @param bool $clean_url
 * @param string $param_name
 * @return array<string, mixed>
 */
function create_navigation_bar($url, int|string $nb_element, $start, $nb_element_page, $clean_url = false, $param_name = 'start'): array
{
    return new PaginationService()
        ->createNavigationBar($url, $nb_element, $start, $nb_element_page, $clean_url, $param_name);
}

/**
 * return an array which will be sent to template to display recent icon
 *
 * @param string $date
 * @param bool $is_child_date
 * @return false|array<string, mixed>
 */
function get_icon($date, $is_child_date = false): false|array
{
    /**
     * @var array<string, mixed> $cache
     * @var array<string, mixed> $user
     */
    global $cache, $user;

    if (empty($date)) {
        return false;
    }

    $recent_period = $user['recent_period'] ?? null;
    $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);

    $get_icon_cache = is_array($cache['get_icon'] ?? null) ? $cache['get_icon'] : [];

    if (! isset($get_icon_cache['title'])) {
        $get_icon_cache['title'] = l10n(
            'photos posted during the last %d days',
            $recent_period
        );
    }

    $icon = [
        'TITLE' => $get_icon_cache['title'],
        'IS_CHILD_DATE' => $is_child_date,
    ];

    if (isset($get_icon_cache[$date])) {
        $cache['get_icon'] = $get_icon_cache;
        return ((bool) $get_icon_cache[$date]) ? $icon : [];
    }

    if (! isset($get_icon_cache['sql_recent_date'])) {
        // Use MySql date in order to standardize all recent "actions/queries"
        $get_icon_cache['sql_recent_date'] = pwg_db_get_recent_period($recent_period);
    }

    $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
    $cache['get_icon'] = $get_icon_cache;

    return $get_icon_cache[$date] ? $icon : [];
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
 * get pwg_token used to prevent csrf attacks
 */
function get_pwg_token(): string
{
    return new CsrfService()
        ->getToken();
}

/**
 * breaks the script execution if the given value doesn't match the given
 * pattern. This should happen only during hacking attempts.
 * @param string $param_name
 * @param bool $is_array
 * @param string $pattern
 * @param bool $mandatory
 * @param array<int|string, mixed> $param_array
 */
function check_input_parameter($param_name, array $param_array, $is_array, $pattern, $mandatory = false): ?true
{
    return new InputValidator()
        ->validate($param_name, $param_array, $is_array, $pattern, $mandatory);
}

/**
 * get localized privacy level values
 *
 * @return string[]
 */
function get_privacy_level_options(): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $available_permission_levels = $conf['available_permission_levels'];
    $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];

    $options = [];
    $label = '';
    foreach (array_reverse($available_permission_levels) as $level) {
        // config_default.inc.php seeds this as [0, 1, 2, 4, 8] (always
        // int); a non-int entry would come from a broken custom config
        // override and has no meaningful privacy level to render.
        if (! is_int($level)) {
            continue;
        }
        if ($level == 0) {
            $label = l10n('Everybody');
        } else {
            if ((bool) strlen($label)) {
                $label .= ', ';
            }
            $label .= l10n(sprintf('Level %d', $level));
        }
        $options[$level] = $label;
    }
    return $options;
}

/**
 * return the branch from the version. For example version 11.1.2 is on branch 11
 *
 * @param string $version
 */
function get_branch_from_version($version): string
{
    // the algorithm is a bit complicated to just retrieve the first digits before
    // the first ".". It's because before version 11.0.0, we used to take the 2 first
    // digits, ie version 2.2.4 was on branch 2.2
    return implode('.', array_slice(explode('.', $version), 0, 1));
}

/**
 * return the device type: mobile, tablet or desktop
 */
function get_device(): string
{
    $device = SessionService::get()->getSessionVar('device');

    if (! is_string($device)) {
        // No UA-sniffing library (removed, no replacement — see
        // docs/adr/0021-native-platform-first-library-policy.md): the v17
        // responsive CSS (P30) removes the need for a separate mobile theme
        // via device detection. mobile_theme() still honors an explicit
        // ?mobile=1/0 override independent of this default.
        $device = 'desktop';
        SessionService::get()->setSessionVar('device', $device);
    }

    return $device;
}

/**
 * return true if mobile theme should be loaded
 */
function mobile_theme(): bool
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (empty($conf['mobile_theme'])) {
        return false;
    }

    if (isset($_GET['mobile'])) {
        $is_mobile_theme = get_boolean($_GET['mobile']);
        SessionService::get()->setSessionVar('mobile_theme', $is_mobile_theme);
    } else {
        $session_mobile_theme = SessionService::get()->getSessionVar('mobile_theme');
        $is_mobile_theme = is_bool($session_mobile_theme) ? $session_mobile_theme : null;
    }

    if ($is_mobile_theme === null) {
        $is_mobile_theme = (get_device() == 'mobile');
        SessionService::get()->setSessionVar('mobile_theme', $is_mobile_theme);
    }

    return $is_mobile_theme;
}

/**
 * check url format
 *
 * @param string $url
 * @return bool
 */
function url_check_format($url)
{
    if (str_contains($url, '"')) {
        return false;
    }

    if (! str_starts_with($url, 'http://') and ! str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * check email format
 */
function email_check_format(?string $mail_address): bool
{
    return filter_var($mail_address, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * returns the number of available comments for the connected user
 */
function get_nb_available_comments(): int
{
    /** @var array<string, mixed> $user */
    global $user;
    if (! isset($user['nb_available_comments'])) {
        $where = [];
        if (! \Piwigo\Auth\AccessControl::isAdmin()) {
            $where[] = 'validated=\'true\'';
        }
        $where[] = (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
            'forbidden_categories' => 'category_id',
            'forbidden_images' => 'ic.image_id',
        ], '', true);

        $query = '
SELECT COUNT(DISTINCT(com.id))
  FROM ' . Tables::imageCategory() . ' AS ic
    INNER JOIN ' . Tables::comments() . ' AS com
    ON ic.image_id = com.image_id
  WHERE ' . implode('
    AND ', $where);
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$user['nb_available_comments']] = $row;

        $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

        single_update(
            Tables::userCache(),
            [
                'nb_available_comments' => $user['nb_available_comments'],
            ],
            [
                'user_id' => $user_id,
            ]
        );
    }
    $nb_available_comments = $user['nb_available_comments'];
    return is_numeric($nb_available_comments) ? (int) $nb_available_comments : 0;
}

/**
 * Compare two versions with version_compare after having converted
 * single chars to their decimal values.
 * Needed because version_compare does not understand versions like '2.5.c'.
 * @since 2.6
 *
 * @param string $a
 * @param string $b
 * @param string $op
 */
function safe_version_compare($a, $b, $op = null): int|bool
{
    $replace_chars = (fn (array $m): string => (string) ord(strtolower(is_string($m[1] ?? null) ? $m[1] : '')[0]));

    // add dot before groups of letters (version_compare does the same thing)
    $a = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $a);
    $b = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $b);

    // apply ord() to any single letter
    $a = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, (string) $a);
    $b = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, (string) $b);

    if (empty($op)) {
        return version_compare((string) $a, (string) $b);
    } else {
        return version_compare((string) $a, (string) $b, $op);
    }
}

/**
 * Checks if the lounge needs to be emptied automatically.
 *
 * @since 12
 */
function check_lounge(): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! isset($conf['lounge_active']) or ! (bool) $conf['lounge_active']) {
        return;
    }

    if (isset($_REQUEST['method']) and in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'])) {
        return;
    }

    // is the oldest photo in the lounge older than lounge maximum waiting time?
    $query = '
SELECT
    image_id,
    date_available,
    NOW() AS dbnow
  FROM ' . Tables::lounge() . '
    JOIN ' . Tables::images() . ' ON image_id = id
  ORDER BY image_id ASC
  LIMIT 1
;';
    $voyagers = query2array($query);
    if ((bool) count($voyagers)) {
        $voyager = $voyagers[0];
        $age = strtotime((string) $voyager['dbnow']) - strtotime((string) $voyager['date_available']);

        $lounge_max_duration = $conf['lounge_max_duration'];
        $lounge_max_duration = is_numeric($lounge_max_duration) ? (int) $lounge_max_duration : 0;
        if ($age > $lounge_max_duration) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
            empty_lounge();
        }
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

    $start_time = get_moment();

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

    $exec_id = pwg_unique_exec_begins('send_piwigo_infos');
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

    [$container_type, $container_version] = get_container_info();

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
        pwg_unique_exec_ends('send_piwigo_infos');
        $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] executed in ' . get_elapsed_time($start_time, get_moment()));
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

    $default_theme = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultTheme();
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

    $piwigo_infos['general_stats']['default_language'] = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultLanguage();

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

        $details = safe_unserialize($update_details);
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
    $remote_apps_start_time = get_moment();

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

    pwg_unique_exec_ends('send_piwigo_infos');
    $logger->info('[' . __FUNCTION__ . '][exec=' . $exec_id . '] executed in ' . get_elapsed_time($start_time, get_moment()));
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

function pwg_unique_exec_begins(string $token_name, int $timeout = 60): false|string
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $exec_id = substr(sha1(random_bytes(1000)), 0, 8);
    $logger->info('[' . $token_name . '][exec=' . $exec_id . '] starts now');

    if (isset($conf[$token_name . '_running'])) {
        $running_token = $conf[$token_name . '_running'];
        $running_token = is_scalar($running_token) ? (string) $running_token : '';
        [$running_exec_id, $running_exec_start_time] = explode('-', $running_token);
        if (time() - (int) $running_exec_start_time > $timeout) {
            $logger->info('[' . $token_name . '][exec=' . $exec_id . '] exec=' . $running_exec_id . ', timeout stopped by another call to the function');
            pwg_unique_exec_ends($token_name);
        }
    }

    $query = '
INSERT IGNORE
  INTO ' . Tables::config() . '
  SET param="' . $token_name . '_running"
    , value="' . $exec_id . '-' . time() . '"
;';
    pwg_query($query);

    $row = pwg_db_fetch_row(pwg_query('SELECT value FROM ' . Tables::config() . ' WHERE param = "' . $token_name . '_running"'));
    assert($row !== null);
    [$running_exec] = $row;
    [$running_exec_id] = explode('-', (string) $running_exec);

    if ($running_exec_id != $exec_id) {
        $logger->info('[' . $token_name . '][exec=' . $exec_id . '] skip');
        return false;
    }
    $logger->info('[' . $token_name . '][exec=' . $exec_id . '] wins the race and gets the token!');

    return $exec_id;
}

function pwg_unique_exec_is_running(string $token_name): bool
{
    $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::config() . '
  WHERE param = "' . $token_name . '_running"
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$counter] = $row;

    return $counter > 0;
}

function pwg_unique_exec_ends(string $token_name): void
{
    /** @var Logger $logger */
    global $logger;

    conf_delete_param($token_name . '_running');
    $logger->info('[' . $token_name . '] ends now');
}

/**
 * Detect if Piwigo is running in a containerized environment
 * Assume all containers are Linux based and don't enforce php open_basedir rules
 * Doesn't differentiate between VMs, Mutual hosting and bare metal installs
 *
 * Possible values :
 *  ('none',null)                 => PHP is not running in a container
 *  ('Official',<VersionCode>)    => PHP is running in a official container
 *  ('LinuxServer',<VersionCode>) => PHP is running in a LinuxServer container
 *  ('Unknown',null)              => PHP is running in a non-identified container
 *
 * @since 16.3
 *
 * @return array{0: string, 1: ?string}
 */
function get_container_info(): array
{
    // Check if OS is Linux and PHP doesn't restrict opening files
    if ((strtoupper(substr(PHP_OS, 0, 5)) === 'LINUX' and empty(ini_get('open_basedir')))) {
        if (file_exists('/proc/2/sched')) { // Check if PID2 exist
            $file = file_get_contents('/proc/2/sched'); // Read PID2 name
            if ((bool) $file and str_starts_with($file, 'kthreadd')) { // If PID 2 is kthreadd PHP is not running in a container
                return ['none', null];
            }
        }

        // PHP is running in a container, trying to determine container type
        $info_file_path = '/var/www/html/piwigo-docker.info';
        $info_file_linuxserver = '/build_version';

        // Check for official container tagfile
        if (is_readable($info_file_path)) {
            $file_lines = @file($info_file_path);
            if (is_array($file_lines) and trim($file_lines[0]) === 'Official Piwigo container') {
                $container_version = null;
                // Take the last line and remove prefix (Build Version)
                if ((bool) preg_match('/^Build Version (.*)$/', $file_lines[count($file_lines) - 1], $matches)) {
                    $container_version = $matches[1];
                }
                return ['Official', $container_version];
            }
        }
        // Check for LinuxServer tagfile
        elseif (is_readable($info_file_linuxserver)) {
            $file_lines = file($info_file_linuxserver);
            if (is_array($file_lines) and str_starts_with($file_lines[0], 'Linuxserver.io')) {
                $container_version = null;
                if ((bool) preg_match('/version:\s*(.*)$/', $file_lines[0], $matches)) {
                    $container_version = $matches[1];
                }
                return ['LinuxServer.io', $container_version];
            }
        }
        // If no tagfile are found, default to unkown
        return ['Unknown', null];
    } else {
        // If the OS is not Linux or PHP basedir are enforced, assume PHP is not in a container
        return ['none', null];
    }
}

/**
 * Checks if the provided string is valid for a comparison test with a datetime field in MySQL
 *
 * Possible values : YYYY-MM-DD HH-MM-SS or YYYY-MM-DD
 *
 * @since 16.3
 */
function is_valid_mysql_datetime(string $datetime): bool
{
    // first we check the full date+time
    $format = 'Y-m-d H:i:s';
    $date = DateTime::createFromFormat($format, $datetime);
    if ((bool) $date and $date->format($format) === $datetime) {
        return true;
    }

    // in case it fails, let's check with only date and no time
    $format = 'Y-m-d';
    $date = DateTime::createFromFormat($format, $datetime);
    if ((bool) $date and $date->format($format) === $datetime) {
        return true;
    }

    return false;
}
