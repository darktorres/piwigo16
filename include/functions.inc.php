<?php

declare(strict_types=1);

use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\___
 */

include_once(PHPWG_ROOT_PATH .'include/functions_plugins.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_user.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_cookie.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_session.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_category.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_html.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_tag.inc.php');
include_once(PHPWG_ROOT_PATH .'include/functions_url.inc.php');
include_once(PHPWG_ROOT_PATH .'include/derivative_params.inc.php');
include_once(PHPWG_ROOT_PATH .'include/derivative_std_params.inc.php');
include_once(PHPWG_ROOT_PATH .'include/derivative.inc.php');


/**
 * returns the current microsecond since Unix epoch
 */
function micro_seconds(): string
{
    $t1 = explode(' ', microtime());
    $t2 = explode('.', $t1[0]);
    $t2 = $t1[1].substr($t2[1], 0, 6);
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
    return number_format($end - $start, 3, '.', ' ').' s';
}

/**
 * Read an int from $_GET/$_POST/$_REQUEST, casting and returning null if absent.
 *
 * @param array<string,mixed> $source
 */
function input_int(string $key, ?int $default = null, array $source = []): ?int
{
    $src = $source ?: ($_POST + $_GET);
    return isset($src[$key]) ? (is_numeric($src[$key]) ? (int) $src[$key] : 0) : $default;
}

/**
 * Read a trimmed string from $_GET/$_POST/$_REQUEST, returning null if absent.
 *
 * @param array<string,mixed> $source
 */
function input_string(string $key, ?string $default = null, array $source = []): ?string
{
    $src = $source ?: ($_POST + $_GET);
    return isset($src[$key]) ? trim(is_scalar($src[$key]) ? (string) $src[$key] : '') : $default;
}

/**
 * Read a boolean from $_GET/$_POST/$_REQUEST (truthy string → true), returning null if absent.
 *
 * @param array<string,mixed> $source
 */
function input_bool(string $key, ?bool $default = null, array $source = []): ?bool
{
    $src = $source ?: ($_POST + $_GET);
    return isset($src[$key]) ? (bool) $src[$key] : $default;
}

/**
 * returns the part of the string after the last "."
 *
 * @param string $filename
 */
function get_extension($filename): string
{
    $ext = strrchr($filename, '.');
    return $ext !== false ? substr($ext, 1) : '';
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
    if (!is_dir($dir)) {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
        }
        $umask = umask(0);
        set_error_handler(static fn (): bool => true);
        try {
            $mkd = mkdir($dir, \Piwigo\Config\Config::chmodValue(), ($flags & MKGETDIR_RECURSIVE) ? true : false);
        } finally {
            restore_error_handler();
        }
        umask($umask);
        if ($mkd == false) {
            !($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("$dir ".l10n('no write access'));
            return false;
        }
        if ($flags & MKGETDIR_PROTECT_HTACCESS) {
            $file = $dir.'/.htaccess';
            if (!file_exists($file) && is_writable($dir)) {
                file_put_contents($file, 'deny from all');
            }
        }
        if ($flags & MKGETDIR_PROTECT_INDEX) {
            $file = $dir.'/index.htm';
            if (!file_exists($file) && is_writable($dir)) {
                file_put_contents($file, 'Not allowed!');
            }
        }
    }
    if (!is_writable($dir)) {
        !($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("$dir ".l10n('no write access'));
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
    for ($i = 0; $i < strlen((string) $Str); $i++) {
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
            if ((++$i == strlen((string) $Str)) || ((ord($Str[$i]) & 0xC0) != 0x80)) {
                return -1;
            }
        }
    }
    return $ret;
}

/**
 * Remove accents from a UTF-8 or ISO-8859-1 string (from wordpress)
 *
 * @return string
 */
function remove_accents(string $string): string
{
    $utf = qualify_utf8($string);
    if ($utf == 0) {
        return $string; // ascii
    }

    if ($utf > 0) {
        $chars = [
        // Decompositions for Latin-1 Supplement
        "\xc3\x80" => 'A', "\xc3\x81" => 'A',
        "\xc3\x82" => 'A', "\xc3\x83" => 'A',
        "\xc3\x84" => 'A', "\xc3\x85" => 'A',
        "\xc3\x87" => 'C', "\xc3\x88" => 'E',
        "\xc3\x89" => 'E', "\xc3\x8a" => 'E',
        "\xc3\x8b" => 'E', "\xc3\x8c" => 'I',
        "\xc3\x8d" => 'I', "\xc3\x8e" => 'I',
        "\xc3\x8f" => 'I', "\xc3\x91" => 'N',
        "\xc3\x92" => 'O', "\xc3\x93" => 'O',
        "\xc3\x94" => 'O', "\xc3\x95" => 'O',
        "\xc3\x96" => 'O', "\xc3\x99" => 'U',
        "\xc3\x9a" => 'U', "\xc3\x9b" => 'U',
        "\xc3\x9c" => 'U', "\xc3\x9d" => 'Y',
        "\xc3\x9f" => 's', "\xc3\xa0" => 'a',
        "\xc3\xa1" => 'a', "\xc3\xa2" => 'a',
        "\xc3\xa3" => 'a', "\xc3\xa4" => 'a',
        "\xc3\xa5" => 'a', "\xc3\xa7" => 'c',
        "\xc3\xa8" => 'e', "\xc3\xa9" => 'e',
        "\xc3\xaa" => 'e', "\xc3\xab" => 'e',
        "\xc3\xac" => 'i', "\xc3\xad" => 'i',
        "\xc3\xae" => 'i', "\xc3\xaf" => 'i',
        "\xc3\xb1" => 'n', "\xc3\xb2" => 'o',
        "\xc3\xb3" => 'o', "\xc3\xb4" => 'o',
        "\xc3\xb5" => 'o', "\xc3\xb6" => 'o',
        "\xc3\xb9" => 'u', "\xc3\xba" => 'u',
        "\xc3\xbb" => 'u', "\xc3\xbc" => 'u',
        "\xc3\xbd" => 'y', "\xc3\xbf" => 'y',
        // Decompositions for Latin Extended-A
        "\xc4\x80" => 'A', "\xc4\x81" => 'a',
        "\xc4\x82" => 'A', "\xc4\x83" => 'a',
        "\xc4\x84" => 'A', "\xc4\x85" => 'a',
        "\xc4\x86" => 'C', "\xc4\x87" => 'c',
        "\xc4\x88" => 'C', "\xc4\x89" => 'c',
        "\xc4\x8a" => 'C', "\xc4\x8b" => 'c',
        "\xc4\x8c" => 'C', "\xc4\x8d" => 'c',
        "\xc4\x8e" => 'D', "\xc4\x8f" => 'd',
        "\xc4\x90" => 'D', "\xc4\x91" => 'd',
        "\xc4\x92" => 'E', "\xc4\x93" => 'e',
        "\xc4\x94" => 'E', "\xc4\x95" => 'e',
        "\xc4\x96" => 'E', "\xc4\x97" => 'e',
        "\xc4\x98" => 'E', "\xc4\x99" => 'e',
        "\xc4\x9a" => 'E', "\xc4\x9b" => 'e',
        "\xc4\x9c" => 'G', "\xc4\x9d" => 'g',
        "\xc4\x9e" => 'G', "\xc4\x9f" => 'g',
        "\xc4\xa0" => 'G', "\xc4\xa1" => 'g',
        "\xc4\xa2" => 'G', "\xc4\xa3" => 'g',
        "\xc4\xa4" => 'H', "\xc4\xa5" => 'h',
        "\xc4\xa6" => 'H', "\xc4\xa7" => 'h',
        "\xc4\xa8" => 'I', "\xc4\xa9" => 'i',
        "\xc4\xaa" => 'I', "\xc4\xab" => 'i',
        "\xc4\xac" => 'I', "\xc4\xad" => 'i',
        "\xc4\xae" => 'I', "\xc4\xaf" => 'i',
        "\xc4\xb0" => 'I', "\xc4\xb1" => 'i',
        "\xc4\xb2" => 'IJ', "\xc4\xb3" => 'ij',
        "\xc4\xb4" => 'J', "\xc4\xb5" => 'j',
        "\xc4\xb6" => 'K', "\xc4\xb7" => 'k',
        "\xc4\xb8" => 'k', "\xc4\xb9" => 'L',
        "\xc4\xba" => 'l', "\xc4\xbb" => 'L',
        "\xc4\xbc" => 'l', "\xc4\xbd" => 'L',
        "\xc4\xbe" => 'l', "\xc4\xbf" => 'L',
        "\xc5\x80" => 'l', "\xc5\x81" => 'L',
        "\xc5\x82" => 'l', "\xc5\x83" => 'N',
        "\xc5\x84" => 'n', "\xc5\x85" => 'N',
        "\xc5\x86" => 'n', "\xc5\x87" => 'N',
        "\xc5\x88" => 'n', "\xc5\x89" => 'N',
        "\xc5\x8a" => 'n', "\xc5\x8b" => 'N',
        "\xc5\x8c" => 'O', "\xc5\x8d" => 'o',
        "\xc5\x8e" => 'O', "\xc5\x8f" => 'o',
        "\xc5\x90" => 'O', "\xc5\x91" => 'o',
        "\xc5\x92" => 'OE', "\xc5\x93" => 'oe',
        "\xc5\x94" => 'R', "\xc5\x95" => 'r',
        "\xc5\x96" => 'R', "\xc5\x97" => 'r',
        "\xc5\x98" => 'R', "\xc5\x99" => 'r',
        "\xc5\x9a" => 'S', "\xc5\x9b" => 's',
        "\xc5\x9c" => 'S', "\xc5\x9d" => 's',
        "\xc5\x9e" => 'S', "\xc5\x9f" => 's',
        "\xc5\xa0" => 'S', "\xc5\xa1" => 's',
        "\xc5\xa2" => 'T', "\xc5\xa3" => 't',
        "\xc5\xa4" => 'T', "\xc5\xa5" => 't',
        "\xc5\xa6" => 'T', "\xc5\xa7" => 't',
        "\xc5\xa8" => 'U', "\xc5\xa9" => 'u',
        "\xc5\xaa" => 'U', "\xc5\xab" => 'u',
        "\xc5\xac" => 'U', "\xc5\xad" => 'u',
        "\xc5\xae" => 'U', "\xc5\xaf" => 'u',
        "\xc5\xb0" => 'U', "\xc5\xb1" => 'u',
        "\xc5\xb2" => 'U', "\xc5\xb3" => 'u',
        "\xc5\xb4" => 'W', "\xc5\xb5" => 'w',
        "\xc5\xb6" => 'Y', "\xc5\xb7" => 'y',
        "\xc5\xb8" => 'Y', "\xc5\xb9" => 'Z',
        "\xc5\xba" => 'z', "\xc5\xbb" => 'Z',
        "\xc5\xbc" => 'z', "\xc5\xbd" => 'Z',
        "\xc5\xbe" => 'z', "\xc5\xbf" => 's',
        // Decompositions for Latin Extended-B
        "\xc8\x98" => 'S', "\xc8\x99" => 's',
        "\xc8\x9a" => 'T', "\xc8\x9b" => 't',
        // Euro Sign
        "\xe2\x82\xac" => 'E',
        // GBP (Pound) Sign
        "\xc2\xa3" => ''];

        $string = strtr($string, $chars);
    } else {
        // Assume ISO-8859-1 if not UTF-8
        $chars['in'] = chr(128).chr(131).chr(138).chr(142).chr(154).chr(158)
          .chr(159).chr(162).chr(165).chr(181).chr(192).chr(193).chr(194)
          .chr(195).chr(196).chr(197).chr(199).chr(200).chr(201).chr(202)
          .chr(203).chr(204).chr(205).chr(206).chr(207).chr(209).chr(210)
          .chr(211).chr(212).chr(213).chr(214).chr(216).chr(217).chr(218)
          .chr(219).chr(220).chr(221).chr(224).chr(225).chr(226).chr(227)
          .chr(228).chr(229).chr(231).chr(232).chr(233).chr(234).chr(235)
          .chr(236).chr(237).chr(238).chr(239).chr(241).chr(242).chr(243)
          .chr(244).chr(245).chr(246).chr(248).chr(249).chr(250).chr(251)
          .chr(252).chr(253).chr(255);

        $chars['out'] = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';

        $string = strtr($string, $chars['in'], $chars['out']);
        $double_chars['in'] = [chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254)];
        $double_chars['out'] = ['OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th'];
        $string = str_replace($double_chars['in'], $double_chars['out'], $string);
    }

    return $string;
}

if (function_exists('mb_strtolower')) {
    /**
     * removes accents from a string and converts it to lower case
     *
     * @param string $term
     * @return string
     */
    function pwg_transliterate(string $term): string
    {
        return remove_accents(mb_strtolower($term, 'utf-8'));
    }
} else {
    /**
     * @ignore
     */
    function pwg_transliterate(string $term): string
    {
        return remove_accents(strtolower((string) $term));
    }
}

/**
 * simplify a string to insert it into an URL
 *
 * @return string
 */
function str2url(string $str): string
{
    $str = $safe = pwg_transliterate($str);
    $str = preg_replace('/[^\x80-\xffa-z0-9_\s\'\:\/\[\],-]/', '', $str);
    $str = preg_replace('/[\s\'\:\/\[\],-]+/', ' ', trim((string) $str));
    $res = str_replace(' ', '_', $str ?? '');

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
    if (\Piwigo\Core\ServiceLocator::has(\Piwigo\Language\LanguageRepository::class)) {
        $languages = [];
        foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Language\LanguageRepository::class)->findIdNameMap() as $id => $name) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }

    // Fallback for pre-boot context (install, early bootstrap)
    $languages = [];
    foreach (\Piwigo\Db\DbConnection::build()
        ->executeQuery('SELECT id, name FROM ' . LANGUAGES_TABLE . ' ORDER BY name ASC')
        ->fetchAllAssociative() as $row) {
        $lang_id = is_scalar($row['id']) ? (string) $row['id'] : '';
        $lang_name = is_scalar($row['name']) ? (string) $row['name'] : '';
        if ($lang_id !== '' && is_dir(PHPWG_ROOT_PATH.'language/'.$lang_id)) {
            $languages[$lang_id] = $lang_name;
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
 *
 * @return bool
 */
function do_log($image_id = null, $image_type = null)
{
    $do_log = \Piwigo\Config\Config::logConf();
    if (is_admin()) {
        $do_log = \Piwigo\Config\Config::historyAdmin();
    }
    if (is_a_guest()) {
        $do_log = \Piwigo\Config\Config::historyGuest();
    }

    return (bool) trigger_change('pwg_log_allowed', $do_log, $image_id, $image_type);
}

/**
 * log the visit into history table
 *
 * @param int $image_id
 * @param string $image_type
 */
function pwg_log(int|string|null $image_id = null, ?string $image_type = null, ?string $format_id = null): bool
{
    $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    if ($image_id !== null) {
        $image_id = (int) $image_id;
    }

    $userId = \Piwigo\Users\CurrentUser::get()->id;
    $lastVisit = is_scalar($user['last_visit'] ?? null) ? (string) $user['last_visit'] : '';
    $update_last_visit = false;
    if (empty($lastVisit) or strtotime($lastVisit) < time() - \Piwigo\Config\Config::sessionLength()) {
        $update_last_visit = true;
    }
    $update_last_visit = trigger_change('pwg_log_update_last_visit', $update_last_visit);

    if ($update_last_visit) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->updateLastVisit($userId);
    }

    if (!do_log($image_id, $image_type)) {
        return false;
    }

    $tags_string = null;
    $pageSection = is_scalar($page['section'] ?? null) ? (string) $page['section'] : '';
    if ('tags' == $pageSection) {
        $tagIds = is_array($page['tag_ids'] ?? null) ? $page['tag_ids'] : [];
        $tags_string = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tagIds));

        if (strlen($tags_string) > 50) {
            // we need to truncate, mysql won't accept a too long string
            $tags_string = substr($tags_string, 0, 50);
            // the last tag_id may have been truncated itself, so we must remove it
            $comma_pos = strrpos($tags_string, ',');
            if ($comma_pos !== false) {
                $tags_string = substr($tags_string, 0, $comma_pos);
            }
        }
    }

    $ip_raw = $_SERVER['REMOTE_ADDR'];
    $ip = is_scalar($ip_raw) ? (string) $ip_raw : '';
    // IPv6 should not be longer than 39 chars, and that is the maximum length of
    // the column in the database, but in case it would be longer, let's truncate it.
    if (strlen($ip) > 39) {
        $ip = substr($ip, 0, 39);
    }

    // If plugin developers add their own sections, Piwigo will automatically add it in the history.section enum column
    if ($pageSection !== '') {
        // set cache if not available
        if (!\Piwigo\Config\Config::has('history_sections_cache')) {
            conf_update_param('history_sections_cache', \Piwigo\Db\SchemaHelper::getEnums(HISTORY_TABLE, 'section'), true);
        }

        $history_sections_cache = safe_unserialize(\Piwigo\Config\Config::historySectionsCache() ?? '');
        \Piwigo\Config\Config::override('history_sections_cache', $history_sections_cache);
        if (
            in_array($pageSection, $history_sections_cache)
            or in_array(strtolower($pageSection), array_map(static fn (mixed $s): string => strtolower(is_scalar($s) ? (string) $s : ''), $history_sections_cache))
        ) {
            $section = $pageSection;
        } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $pageSection)) {
            $history_sections = \Piwigo\Db\SchemaHelper::getEnums(HISTORY_TABLE, 'section');
            $history_sections[] = $pageSection;

            // alter history table structure, to include a new section
            \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)->executeStatement(
                "ALTER TABLE " . HISTORY_TABLE . " CHANGE section section enum('" .
                implode("','", array_unique($history_sections)) . "') DEFAULT NULL"
            );

            // and refresh cache
            conf_update_param('history_sections_cache', \Piwigo\Db\SchemaHelper::getEnums(HISTORY_TABLE, 'section'), true);

            $section = $pageSection;
        }
    }

    $category = is_array($page['category'] ?? null) ? $page['category'] : null;
    $categoryId = $category !== null && is_scalar($category['id'] ?? null) ? (string) $category['id'] : 'NULL';
    $searchId = is_scalar($page['search_id'] ?? null) ? (string) $page['search_id'] : 'NULL';
    $authKeyId = is_scalar($page['auth_key_id'] ?? null) ? (string) $page['auth_key_id'] : 'NULL';
    $history_id = \Piwigo\Core\ServiceLocator::get(\Piwigo\History\HistoryRepository::class)
        ->insertLog(
            $userId,
            $ip,
            isset($section) ? $section : null,
            $categoryId !== 'NULL' ? $categoryId : null,
            $searchId !== 'NULL' ? $searchId : null,
            $image_id,
            isset($image_type) ? $image_type : null,
            $format_id ?? null,
            $authKeyId !== 'NULL' ? $authKeyId : null,
            isset($tags_string) ? $tags_string : null
        );
    if ($history_id % 1000 == 0) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions_history.inc.php');
        history_summarize(50000);
    }

    if (\Piwigo\Config\Config::historyAutopurgeEvery() > 0 and $history_id % \Piwigo\Config\Config::historyAutopurgeEvery() == 0) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions_history.inc.php');
        history_autopurge();
    }

    return true;
}

/**
 * @param int[]|int|string $object_id
 * @param array<mixed> $details
 */
function pwg_activity(string $object, array|int|string $object_id, string $action, array $details = []): void
{
    // in case of uploadAsync, do not log the automatic login as an independant activity
    if (isset($_REQUEST['method']) and 'pwg.images.uploadAsync' == $_REQUEST['method'] and 'login' == $action) {
        return;
    }

    if (isset($_REQUEST['method']) and 'pwg.plugins.performAction' == $_REQUEST['method'] and $_REQUEST['action'] != $action) {
        // for example, if you "restore" a plugin, the internal sequence will perform deactivate/uninstall/install/activate.
        // We only want to keep the last call to pwg_activity with the "restore" action.
        return;
    }

    $object_ids = is_array($object_id) ? $object_id : [$object_id];

    if (isset($_REQUEST['method'])) {
        $details['method'] = $_REQUEST['method'];
    } else {
        $details['script'] = script_basename();

        if ('admin' == $details['script'] and isset($_GET['page'])) {
            $details['script'] .= '/'.(is_scalar($_GET['page']) ? (string)$_GET['page'] : '');
        }
    }

    if ('autoupdate' == $action) {
        // autoupdate on a plugin can happen anywhere, the "script/method" is not meaningfull
        unset($details['method']);
        unset($details['script']);
    }

    $user_agent = null;
    if ('user' == $object and 'login' == $action and isset($_SERVER['HTTP_USER_AGENT'])) {
        $user_agent = strip_tags(is_scalar($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
    }

    if (isset($_SESSION['connected_with']) and 'api_key' === $_SESSION['connected_with'] and isset($_SERVER['HTTP_USER_AGENT'])) {
        $details['connected_with'] = 'api_key';
        $user_agent = strip_tags(is_scalar($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
    }

    // we want to know if the login is automatic with remember_me (auto_login)
    // or with an authentication key provided in the URL (auth_key_login)
    if ('user' == $object and 'login' == $action) {
        if (function_exists('debug_backtrace')) {
            $called_functions = array_flip(array_column(debug_backtrace(), 'function'));
            foreach (['auto_login', 'auth_key_login'] as $auth_function) {
                if (isset($called_functions[$auth_function])) {
                    $details['auth_function'] = $auth_function;
                }
            }
        }
    }

    if ('photo' == $object and 'add' == $action and !isset($details['sync'])) {
        $details['added_with'] = 'app';
        if (isset($_SERVER['HTTP_REFERER']) and preg_match('/page=photos_add/', is_scalar($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '')) {
            $details['added_with'] = 'browser';
        }
    }

    if (in_array($object, ['album', 'photo']) and 'delete' == $action and isset($_GET['page']) and 'site_update' == $_GET['page']) {
        $details['sync'] = true;
    }

    if ('tag' == $object and 'delete' == $action and isset($_POST['destination_tag'])) {
        $details['action'] = 'merge';
        $details['destination_tag'] = $_POST['destination_tag'];
    }

    $inserts = [];
    $details_insert = serialize($details);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $session_id = !empty(session_id()) ? session_id() : 'none';

    foreach ($object_ids as $loop_object_id) {
        $performed_by = \Piwigo\Users\CurrentUser::isInitialized()
            ? \Piwigo\Users\CurrentUser::get()->id
            : 0; // on a plugin autoupdate, $user is not yet loaded

        if ('logout' == $action) {
            $performed_by = $loop_object_id;
        }

        $inserts[] = [
          'object' => $object,
          'object_id' => $loop_object_id,
          'action' => $action,
          'performed_by' => $performed_by,
          'session_idx' => $session_id,
          'ip_address' => $ip_address,
          'details' => $details_insert,
          'user_agent' => $user_agent ?? '',
        ];
    }

    mass_inserts(ACTIVITY_TABLE, array_keys($inserts[0]), $inserts);
}

/**
 * Computes the difference between two dates.
 * returns a DateInterval object or a stdClass with the same attributes
 * http://stephenharris.info/date-intervals-in-php-5-2
 *
 * @param DateTime $date1
 * @param DateTime $date2
 */
function dateDiff($date1, $date2): \DateInterval
{
    return $date1->diff($date2);
}

/**
 * converts a string into a DateTime object
 *
 *  int|string|null  timestamp or datetime string
 * @param string $format input format respecting date() syntax
 * @return DateTime|false
 */
function str2DateTime(int|string|null $original, $format = null)
{
    if (empty($original)) {
        return false;
    }

    if (!empty($format)) {// from known date format
        return DateTime::createFromFormat('!'.$format, (string) $original); // ! char to reset fields to UNIX epoch
    } else {
        $t = trim((string) $original, '0123456789');
        if (empty($t)) { // from timestamp
            return new DateTime('@'.$original);
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
            if (!isset($ymdhms[3])) {
                $ymdhms[3] = 0;
            }
            if (!isset($ymdhms[4])) {
                $ymdhms[4] = 0;
            }
            if (!isset($ymdhms[5])) {
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
 *  int|string|null  timestamp or datetime string
 * @param array $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
 * @param string $format input format respecting date() syntax
 * @return string
 */
/** @param string[]|true|null $show */
function format_date_legacy(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
{
    $date = ($original instanceof \DateTime) ? $original : str2DateTime($original, $format);

    if (!$date) {
        return l10n('N/A');
    }

    if ($show === null || $show === true) {
        $show = ['day_name', 'day', 'month', 'year'];
    }

    $print = '';
    if (in_array('day_name', $show)) {
        $print .= \Piwigo\Core\Lang::day((int) $date->format('w')).' ';
    }

    if (in_array('day', $show)) {
        $print .= $date->format('j').' ';
    }

    if (in_array('month', $show)) {
        $print .= \Piwigo\Core\Lang::month((int) $date->format('n')).' ';
    }

    if (in_array('year', $show)) {
        $print .= $date->format('Y').' ';
    }

    if (in_array('time', $show)) {
        $temp = $date->format('H:i');
        if ($temp != '00:00') {
            $print .= $temp.' ';
        }
    }

    return trim($print);
}

/**
 * returns a formatted and localized date for display
 *
 *  int|string|null  timestamp or datetime string
 * @param array $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
 *    THIS PARAMETER IS PLANNED TO CHANGE
 * @param string $format input format respecting date() syntax
 * @return string
 * @since 16
 */
/** @param string[]|true|null $show */
function format_date(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
{
    $userLanguage = \Piwigo\Users\CurrentUser::isInitialized()
        ? \Piwigo\Users\CurrentUser::get()->language
        : 'en_UK';

    $date = ($original instanceof \DateTime) ? $original : str2DateTime($original, $format);

    if (!$date) {
        return l10n('N/A');
    }

    if ($show === null || $show === true) {
        $show = ['day_name', 'day', 'month', 'year'];
    }

    // use IntlDateFormatter for proper i18n need pkg php-intl
    if (class_exists('IntlDateFormatter')
      and in_array('year', $show)
      and in_array('month', $show)
    ) {
        $timeType = in_array('time', $show) ? IntlDateFormatter::MEDIUM : IntlDateFormatter::NONE;
        $dateType = IntlDateFormatter::FULL;

        if (!in_array('day_name', $show)) {
            $dateType = IntlDateFormatter::LONG;
        }

        $fmt = new IntlDateFormatter($userLanguage, $dateType, $timeType);
        $formatted = $fmt->format($date);
        return $formatted !== false ? $formatted : l10n('N/A');
    }

    return format_date_legacy($original, $show, $format);
}

/**
 * Format a "From ... to ..." string from two dates
 * @param string $from
 * @param string $to
 * @param boolean $full
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
 *  int|string|null  timestamp or datetime string
 * @param string $stop year,month,week,day,hour,minute,second
 * @param string $format input format respecting date() syntax
 * @param bool $with_text append "ago" or "in the future"
 * @return string
 */
function time_since(int|string|null $original, string $stop = 'minute', ?string $format = null, bool $with_text = true, bool $with_week = true, bool $only_last_unit = false): string
{
    $date = str2DateTime($original, $format);

    if (!$date) {
        return l10n('N/A');
    }

    $now = new DateTime();
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
        $chunks['week'] = (int)floor($chunks['day'] / 7);
        $chunks['day'] = $chunks['day'] - $chunks['week'] * 7;
    }

    $j = array_search($stop, array_keys($chunks));

    $print = '';
    $i = 0;

    if (!$only_last_unit) {
        foreach ($chunks as $name => $value) {
            if ($value != 0) {
                $print .= ' '.l10n_dec('%d '.$name, '%d '.$name.'s', $value);
            }
            if (!empty($print) && $i >= $j) {
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
                $print = l10n_dec('%d '.$name, '%d '.$name.'s', $value);
            }
            if (!empty($print) && $i >= $j) {
                break;
            }
            $i++;
        }
    }

    $print = trim($print);

    if ($with_text) {
        if ($diff->invert) {
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
 * @param string $default if _$original_ is empty
 * @return string
 */
function transform_date($original, $format_in, $format_out, $default = null): ?string
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
 */
function pwg_debug(string $string): void
{
    $t2 = is_numeric($GLOBALS['t2'] ?? null) ? (float) $GLOBALS['t2'] : 0.0;
    $now = explode(' ', microtime());
    $now2 = explode('.', $now[0]);
    $now2Float = (float) ($now[1].'.'.$now2[1]);
    $time = number_format($now2Float - $t2, 3, '.', ' ').' s';
    if (!isset($GLOBALS['debug']) || !is_string($GLOBALS['debug'])) {
        $GLOBALS['debug'] = '';
    }
    $GLOBALS['debug'] .= '<p>';
    $GLOBALS['debug'] .= '['.$time.', ';
    $GLOBALS['debug'] .= \Piwigo\Core\PageState::current()->countQueries.' queries] : '.$string;
    $GLOBALS['debug'] .= "</p>\n";
}

/**
 * Redirects to the given URL (HTTP method).
 * once this function called, the execution doesn't go further
 * (presence of an exit() instruction.
 *
 * @param string $url
 */
function redirect_http($url): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    // default url is on html format
    $url = html_entity_decode($url);
    header('Request-URI: '.$url);
    header('Content-Location: '.$url);
    header('Location: '.$url);
    exit();
}

/**
 * Redirects to the given URL (HTML method).
 * once this function called, the execution doesn't go further
 * (presence of an exit() instruction.
 *
 * @param string $url
 * @param string $msg
 * @param integer $refresh_time
 */
function redirect_html($url, $msg = '', $refresh_time = 0): void
{
    if (!\Piwigo\Core\LanguageStack::initialized() || !\Piwigo\Template\TemplateRegistry::isInitialized()) {
        \Piwigo\Users\CurrentUser::setRawAttributes(build_user(\Piwigo\Config\Config::guestId(), true));
        load_language('common.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH.PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
        $tpl = new Template(PHPWG_ROOT_PATH.'themes', get_default_theme());
        \Piwigo\Template\TemplateRegistry::set($tpl);
    } elseif (defined('IN_ADMIN') and IN_ADMIN) {
        $tpl = new Template(PHPWG_ROOT_PATH.'themes', get_default_theme());
        \Piwigo\Template\TemplateRegistry::set($tpl);
    }

    if (empty($msg)) {
        $msg = nl2br(l10n('Redirection...'));
    }

    $refresh = $refresh_time;
    $url_link = $url;
    $title = 'redirection';

    $tpl = \Piwigo\Template\TemplateRegistry::current();
    $tpl->set_filenames([ 'redirect' => 'redirect.tpl' ]);

    include(PHPWG_ROOT_PATH.'include/page_header.php');

    $tpl->set_filenames([ 'redirect' => 'redirect.tpl' ]);
    $tpl->assign('REDIRECT_MSG', $msg);

    $tpl->parse('redirect');

    include(PHPWG_ROOT_PATH.'include/page_tail.php');

    exit();
}

/**
 * Redirects to the given URL (automatically choose HTTP or HTML method).
 * once this function called, the execution doesn't go further
 * (presence of an exit() instruction.
 *
 * @param string $url
 * @param string $msg
 * @param integer $refresh_time
 */
function redirect($url, $msg = '', $refresh_time = 0): void
{
    // with RefeshTime <> 0, only html must be used
    if (\Piwigo\Config\Config::defaultRedirectMethod() == 'http'
        and $refresh_time == 0
        and !headers_sent()
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
 * @return array
 */
/** @return array<string, string> */
function get_pwg_themes(bool $show_mobile = false): array
{
    $themes = [];

    if (\Piwigo\Core\ServiceLocator::has(\Piwigo\Theme\ThemeRepository::class)) {
        $rows = \Piwigo\Core\ServiceLocator::get(\Piwigo\Theme\ThemeRepository::class)->findAll();
    } else {
        // Fallback for pre-boot context
        $rows = query2array('SELECT id, name FROM ' . THEMES_TABLE . ' ORDER BY name ASC;');
    }

    foreach ($rows as $row) {
        if ($row['id'] == \Piwigo\Config\Config::mobilTheme()) {
            if (!$show_mobile) {
                continue;
            }
            $row['name'] = (is_scalar($row['name']) ? (string) $row['name'] : '').(' ('.l10n('Mobile').')');
        }
        $theme_id = is_scalar($row['id']) ? (string) $row['id'] : '';
        if (check_theme_installed($theme_id)) {
            $themes[ $theme_id ] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
    }

    // plugins want remove some themes based on user status maybe?
    $themes = trigger_change('get_pwg_themes', $themes);

    return $themes;
}

/**
 * check if a theme is installed (directory exsists)
 */
function check_theme_installed(string $theme_id): bool
{
    return file_exists(\Piwigo\Config\Config::themesDir().'/'.$theme_id.'/'.'themeconf.inc.php');
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
 * @param array $element_info element information from db (at least 'path')
 * @return string
 */
/** @param array<string,mixed> $element_info */
function get_element_path(array $element_info): string
{
    $path = is_scalar($element_info['path']) ? (string) $element_info['path'] : '';
    if (!url_is_remote($path)) {
        $path = PHPWG_ROOT_PATH.$path;
    }
    return $path;
}


/**
 * fill the current user caddie with given elements, if not already in caddie
 *
 * @param int[] $elements_id
 */
function fill_caddie($elements_id): void
{
    $userId = \Piwigo\Users\CurrentUser::get()->id;

    $query = '
SELECT element_id
  FROM '.CADDIE_TABLE.'
  WHERE user_id = '.$userId.'
;';
    $in_caddie = query2array($query, null, 'element_id');

    $caddiables = array_diff($elements_id, $in_caddie);

    $datas = [];

    foreach ($caddiables as $caddiable) {
        $datas[] = [
          'element_id' => $caddiable,
          'user_id' => $userId,
          ];
    }

    if (count($caddiables) > 0) {
        mass_inserts(CADDIE_TABLE, ['element_id','user_id'], $datas);
    }
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
 * @param string|null $key
 * @return string
 */
function l10n(?string $key): string
{
    $args = func_num_args() > 1 ? array_slice(func_get_args(), 1) : [];
    return \Piwigo\Core\Lang::t($key ?? '', ...$args);
}

/**
 * returns the printf value for strings including %d
 * returned value is concorded with decimal value (singular, plural)
 *
 * @param string $singular_key
 * @param string $plural_key
 * @param int $decimal
 */
function l10n_dec($singular_key, $plural_key, $decimal): string
{
    $info = \Piwigo\Core\LanguageStack::info();
    $zero_plural = !empty($info['zero_plural']);

    return
      sprintf(
          l10n((
              (($decimal > 1) or ($decimal == 0 and $zero_plural))
          ? $plural_key
          : $singular_key
          )),
          $decimal
      );
}

/**
 * returns a single element to use with l10n_args
 *
 * @param string $key translation key
 *   if args is a array, each values are used on sprintf
 * @return array<mixed>
 */
function get_l10n_args(string $key, mixed $args = ''): array
{
    if (is_array($args)) {
        $key_arg = array_merge([$key], $args);
    } else {
        $key_arg = [$key,  $args];
    }
    return ['key_args' => $key_arg];
}

/**
 * returns a string formated with l10n elements.
 * it is usefull to "prepare" a text and translate it later
 * @see get_l10n_args()
 *
 * @param array $key_args one l10n_args element or array of l10n_args elements
 * @param string $sep used when translated elements are concatened
 */
/** @param array<mixed>|string $key_args */
function l10n_args(array|string $key_args, string $sep = "\n"): string
{
    if (is_array($key_args)) {
        $result = '';
        foreach ($key_args as $key => $element) {
            if ($result !== '') {
                $result .= $sep;
            }

            if ($key === 'key_args') {
                $element = is_array($element) ? $element : [];
                $shifted = array_shift($element);
                array_unshift($element, l10n(is_string($shifted) ? $shifted : '')); // translate the key
                $formatted = call_user_func_array(sprintf(...), $element);
                $result .= is_scalar($formatted) ? (string) $formatted : '';
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

/**
 * returns the corresponding value from $themeconf if existing or an empty string
 *
 * @param string $key
 */
function get_themeconf($key): mixed
{
    /** @var \Piwigo\Template\Template $template */
    $template = $GLOBALS['template'];
    return $template->get_themeconf($key);
}

/**
 * Returns webmaster mail address depending on \Piwigo\Config\Config::webmasterId()
 *
 * @return string
 */
function get_webmaster_mail_address(): string
{
    $userFields = \Piwigo\Config\Config::userFields();
    $email = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->getWebmasterEmail(
            $userFields['email'],
            $userFields['id'],
            USERS_TABLE,
            \Piwigo\Config\Config::webmasterId()
        );

    $email = trigger_change('get_webmaster_mail_address', $email);

    return (string) $email;
}

/**
 * Add configuration parameters from database to global $conf array
 *
 * @param string $condition SQL condition
 */
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
        // If the field is true or false, the variable is transformed into a boolean value.
        if ($val == 'true') {
            $val = true;
        } elseif ($val == 'false') {
            $val = false;
        }
        \Piwigo\Config\Config::override(is_scalar($row['param']) ? (string) $row['param'] : '', $val);
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
    [$param, $value] = ['pwg_is_dbconf_writeable_'.generate_key(12), date('c').' '.generate_key(20)];

    conf_update_param($param, $value);
    $dbvalue = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery('SELECT value FROM ' . CONFIG_TABLE . ' WHERE param = ?', [$param])
        ->fetchOne();

    if ($dbvalue != $value) {
        return false;
    }

    conf_delete_param($param);
    return true;
}

/**
* Add or update a config parameter
*
* @param mixed $value the value to store (arrays/objects will be serialized)
* @param callable-string|null $parser function to apply to the value before save in database
     (eg: serialize, json_encode) will not be applied to *$conf* if *$parser* is *true*
*/
function conf_update_param(string $param, mixed $value, bool $updateGlobal = false, ?string $parser = null): void
{
    if ($parser != null) {
        $raw = call_user_func($parser, $value);
        $dbValue = is_scalar($raw) ? (string) $raw : '';
    } elseif (is_array($value) || is_object($value)) {
        $dbValue = addslashes(serialize($value));
    } else {
        $dbValue = \Piwigo\Core\BoolUtil::toString(is_bool($value) ? $value : (is_scalar($value) ? (string) $value : ''));
    }

    if (\Piwigo\Core\ServiceLocator::has(\Doctrine\DBAL\Connection::class)) {
        \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)->executeStatement(
            'INSERT INTO ' . CONFIG_TABLE . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [$param, $dbValue, $dbValue]
        );
    } else {
        \Piwigo\Db\DbConnection::build()->executeStatement(
            'INSERT INTO ' . CONFIG_TABLE . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [$param, $dbValue, $dbValue]
        );
    }

    if ($updateGlobal) {
        \Piwigo\Config\Config::override($param, $value);
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
    if (!is_array($params)) {
        $params = [$params];
    }
    if (empty($params)) {
        return;
    }

    if (\Piwigo\Core\ServiceLocator::has(\Doctrine\DBAL\Connection::class)) {
        $qb = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)->createQueryBuilder()
            ->delete(CONFIG_TABLE);
        $qb->where($qb->expr()->in('param', ':params'))
           ->setParameter('params', $params, \Doctrine\DBAL\ArrayParameterType::STRING);
        $qb->executeStatement();
    } else {
        $qb = \Piwigo\Db\DbConnection::build()->createQueryBuilder()->delete(CONFIG_TABLE);
        $qb->where($qb->expr()->in('param', ':params'))
           ->setParameter('params', $params, \Doctrine\DBAL\ArrayParameterType::STRING);
        $qb->executeStatement();
    }

    foreach ($params as $param) {
        \Piwigo\Config\Config::delete($param);
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
    return \Piwigo\Config\Config::raw($param) ?? $default_value;
}


/**
 * Apply *unserialize* on a value only if it is a string
 * @since 2.7
 *
 * @param array<mixed>|string $value
 * @return array<mixed>
 */
function safe_unserialize(array|string $value): array
{
    if (is_string($value)) {
        $unserialized = unserialize($value);
        return is_array($unserialized) ? $unserialized : [];
    }
    return $value;
}

/**
 * `getimagesize` wrapper that silences PHP warnings (corrupt JPEG, missing
 * APP markers, etc.) without polluting the global error stream. Returns
 * the array on success or false on failure — matching native semantics.
 *
 * @param array<string, mixed>|null $image_info
 * @param-out array<string, mixed> $image_info
 * @return array<int|string, mixed>|false
 */
function pwg_safe_getimagesize(string $filename, ?array &$image_info = null): array|false
{
    set_error_handler(static fn (): bool => true);
    try {
        $result = getimagesize($filename, $image_info);
    } finally {
        restore_error_handler();
    }
    if ($image_info === null) {
        $image_info = [];
    }
    return $result;
}

/**
 * `exif_read_data` wrapper that silences PHP warnings (truncated header,
 * missing extension, etc.). Returns the array on success or false on
 * failure — matching native semantics.
 *
 * @return array<string, mixed>|false
 */
function pwg_safe_exif_read_data(string $filename): array|false
{
    if (!function_exists('exif_read_data')) {
        return false;
    }
    set_error_handler(static fn (): bool => true);
    try {
        return exif_read_data($filename);
    } finally {
        restore_error_handler();
    }
}

/**
 * Apply *json_decode* on a value only if it is a string
 * @since 2.7
 *
 * @param array<mixed>|string $value
 * @return array<mixed>
 */
function safe_json_decode(array|string $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $value;
}

/**
 * Prepends and appends strings at each value of the given array.
 *
 * @param string $prepend_str
 * @param string $append_str
 * @param string[] $array
 * @return string[]
 */
function prepend_append_array_items(array $array, string $prepend_str, string $append_str): array
{
    array_walk($array, function (&$value, $key) use ($prepend_str, $append_str): void {
        $value = "$prepend_str$value$append_str";
    });
    return $array;
}

/**
 * creates an simple hashmap based on a SQL query.
 * choose one to be the key, another one to be the value.
 *
 * @param string $keyname
 * @param string $valuename
 * @return array<mixed>
 */
#[\Deprecated(message: '2.6')]
function simple_hash_from_query(string $query, string $keyname, string $valuename): array
{
    return query2array($query, $keyname, $valuename);
}

/**
 * creates an associative array based on a SQL query.
 * choose one to be the key
 *
 * @param string $keyname
 * @return array<mixed>
 */
#[\Deprecated(message: '2.6')]
function hash_from_query(string $query, string $keyname): array
{
    return query2array($query, $keyname);
}

/**
 * creates a numeric array based on a SQL query.
 * if _$fieldname_ is empty the returned value will be an array of arrays
 * if _$fieldname_ is provided the returned value will be a one dimension array
 *
 * @param string|false $fieldname
 * @return array<mixed>
 */
#[\Deprecated(message: '2.6')]
function array_from_query(string $query, string|false $fieldname = false): array
{
    if (false === $fieldname) {
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
    foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $value) {
        if (!empty($_SERVER[$value])) {
            $filename = strtolower(is_scalar($_SERVER[$value]) ? (string) $_SERVER[$value] : '');
            if (\Piwigo\Config\Config::phpExtensionInUrls() and get_extension($filename) !== 'php') {
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

/**
 * Return \Piwigo\Config\Config::filterPages() value for the current page
 *
 * @param string $value_name
 * @return mixed
 */
function get_filter_page_value($value_name): mixed
{
    $page_name = script_basename();
    /** @var array<string, array<string, mixed>> $filter_pages */
    $filter_pages = \Piwigo\Config\Config::filterPages();

    if (isset($filter_pages[$page_name][$value_name])) {
        return $filter_pages[$page_name][$value_name];
    } elseif (isset($filter_pages['default'][$value_name])) {
        return $filter_pages['default'][$value_name];
    } else {
        return null;
    }
}

/**
 * return the character set used by Piwigo
 */
function get_pwg_charset(): string
{
    return 'utf-8';
}

/**
 * returns the parent (fallback) language of a language.
 * if _$lang_id_ is null it applies to the current language
 * @since 2.6
 *
 * @param string $lang_id
 * @return string|null
 */
function get_parent_language($lang_id = null)
{
    if (empty($lang_id)) {
        $info = \Piwigo\Core\LanguageStack::info();
        $parent = $info['parent'] ?? null;
        return is_string($parent) && $parent !== '' ? $parent : null;
    } else {
        $f = PHPWG_ROOT_PATH.'language/'.$lang_id.'/common.lang.php';
        if (file_exists($f)) {
            include($f);
            return !empty($lang_info['parent']) ? $lang_info['parent'] : null;
        }
    }
    return null;
}

/**
 * includes a language file or returns the content of a language file
 *
 * tries to load in descending order:
 *   param language, user language, default language
 *
 * @param string $dirname
 *  array  can contain
 *     @option string language - language to load
 *     @option bool return - if true the file content is returned
 *     @option bool no_fallback - if true do not load default language
 *     @option bool|string force_fallback - force pre-loading of another language
 *        default language if *true* or specified language
 *     @option bool local - if true load file from local directory
 */
/** @param array<mixed> $options */
function load_language(string $filename, string $dirname = '', array $options = []): string|bool
{
    $user = \Piwigo\Users\CurrentUser::isInitialized()
        ? \Piwigo\Users\CurrentUser::get()->rawAttributes
        : (is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : []);

    // keep trace of plugins loaded files for switch_lang_to() function
    if (!empty($dirname) && !empty($filename) && empty($options['return'])
      && !\Piwigo\Core\LanguageStack::hasPluginFile($dirname, $filename)) {
        \Piwigo\Core\LanguageStack::trackPluginFile($dirname, $filename, $options);
    }

    if (empty($options['return'])) {
        $filename .= '.php';
    }
    if (empty($dirname)) {
        $dirname = PHPWG_ROOT_PATH;
    }
    $dirname .= 'language/';

    $default_language = (\Piwigo\Core\InstallSentinel::isInstalled() and !defined('UPGRADES_PATH')) ?
        get_default_language() : PHPWG_DEFAULT_LANGUAGE;

    // construct list of potential languages
    $languages = [];
    if (!empty($options['language'])) { // explicit language
        $languages[] = $options['language'];
    }
    if (!empty($user['language'])) { // use language
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
        $languages[] = $options['force_fallback'];
    }
    if (empty($options['no_fallback'])) { // default language
        $languages[] = $default_language;
    }

    /** @var list<string> $languages_typed */
    $languages_typed = array_values(array_unique(array_filter($languages, 'is_string')));

    // find first existing
    $source_file       = '';
    $selected_language = '';
    foreach ($languages_typed as $language) {
        $f = !empty($options['local']) ?
          $dirname.$language.'.'.$filename :
          $dirname.$language.'/'.$filename;

        if (file_exists($f)) {
            $selected_language = $language;
            $source_file = $f;
            break;
        }
    }

    if (!empty($source_file)) {
        if (empty($options['return'])) {
            // load forced fallback — sets local $lang/$lang_info which are reset below
            if (isset($options['force_fallback']) && $options['force_fallback'] != $selected_language) {
                $forceFallback = is_scalar($options['force_fallback']) ? (string) $options['force_fallback'] : '';
                $fallback_file = str_replace($selected_language, $forceFallback, $source_file);
                if (is_readable($fallback_file)) {
                    include $fallback_file;
                }
            }

            // load language content into local variables
            $lang = null;
            $lang_info = null;
            if (is_readable($source_file)) {
                include $source_file;
            }
            $load_lang = $lang;
            $load_lang_info = $lang_info;

            // load parent language content into the global stack (preserves reference bridge)
            $currentInfo = \Piwigo\Core\LanguageStack::info();
            $parent_language = !empty($currentInfo['parent']) ? $currentInfo['parent'] : null;

            if (!empty($parent_language) && $parent_language != $selected_language) {
                \Piwigo\Core\LanguageStack::mergeFromFile(
                    str_replace($selected_language, is_scalar($parent_language) ? (string) $parent_language : '', $source_file)
                );
            }

            // merge loaded content into global stack
            \Piwigo\Core\LanguageStack::mergeLang((array)$load_lang);
            \Piwigo\Core\LanguageStack::mergeInfo((array)$load_lang_info);
            return true;
        } else {
            $content = is_readable($source_file) ? file_get_contents($source_file) : false;
            //Note: target charset is always utf-8 $content = convert_charset($content, 'utf-8', $target_charset);
            return $content;
        }
    }

    return false;
}

/**
 * converts a string from a character set to another character set
 *
 * @param string $source_charset
 */
function convert_charset(string $str, string $source_charset, string $dest_charset): string
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
        $result = iconv($source_charset, $dest_charset.'//TRANSLIT', $str);
        return $result !== false ? $result : $str;
    }
    if (function_exists('mb_convert_encoding')) {
        $result = mb_convert_encoding($str, $dest_charset, $source_charset);
        return $result !== false ? $result : $str;
    }
    return $str; // fallback: return unchanged if neither iconv nor mbstring is available
}

/**
 * makes sure a index.htm protects the directory from browser file listing
 */
function secure_directory(string $dir): void
{
    $file = $dir.'/index.htm';
    if (!file_exists($file) && is_writable($dir)) {
        file_put_contents($file, 'Not allowed!');
    }
}

/**
 * returns a "secret key" that is to be sent back when a user posts a form
 *
 * @param int $valid_after_seconds - key validity start time from now
 */
function get_ephemeral_key(int $valid_after_seconds, string $aditionnal_data_to_hash = ''): string
{
    $time = round(microtime(true), 1);
    $remote_addr = is_scalar($_SERVER['REMOTE_ADDR'] ?? '') ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : '';
    return $time.':'.$valid_after_seconds.':'
        .hash_hmac(
            'md5',
            $time.substr($remote_addr, 0, 5).$valid_after_seconds.$aditionnal_data_to_hash,
            \Piwigo\Config\Config::secretKey()
        );
}

/**
 * verify a key sent back with a form
 *
 * @param string $key
 */
function verify_ephemeral_key(string $key, string $aditionnal_data_to_hash = ''): bool
{
    $time = microtime(true);
    $key = explode(':', $key);
    $remote_addr = is_scalar($_SERVER['REMOTE_ADDR'] ?? '') ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : '';
    if (count($key) != 3
        or $key[0] > $time - (float)$key[1] // page must have been retrieved more than X sec ago
        or $key[0] < $time - 3600 // 60 minutes expiration
        or hash_hmac(
            'md5',
            $key[0].substr($remote_addr, 0, 5).$key[1].$aditionnal_data_to_hash,
            \Piwigo\Config\Config::secretKey()
        ) != $key[2]
    ) {
        return false;
    }
    return true;
}

/**
 * return an array which will be sent to template to display navigation bar
 *
 * @param string $url base url of all links
 * @param int $start
 * @param int $nb_element_page
 * @param bool $clean_url
 */
/** @return array<mixed> */
function create_navigation_bar(string $url, int $nb_element, int $start, int $nb_element_page, bool $clean_url = false, string $param_name = 'start'): array
{
    $navbar = [];
    $pages_around = \Piwigo\Config\Config::paginatePagesAround();
    $start_str = $clean_url ? '/'.$param_name.'-' : (!str_contains($url, '?') ? '?' : '&amp;').$param_name.'=';

    if ($start < 0) {
        $start = 0;
    }

    // navigation bar useful only if more than one page to display !
    if ($nb_element > $nb_element_page) {
        $url_start = $url.$start_str;

        $cur_page = $navbar['CURRENT_PAGE'] = $start / $nb_element_page + 1;
        $maximum = ceil($nb_element / $nb_element_page);

        $start = $nb_element_page * round($start / $nb_element_page);
        $previous = $start - $nb_element_page;
        $next = $start + $nb_element_page;
        $last = ($maximum - 1) * $nb_element_page;

        // link to first page and previous page?
        if ($cur_page != 1) {
            $navbar['URL_FIRST'] = $url;
            $navbar['URL_PREV'] = $previous > 0 ? $url_start.$previous : $url;
        }
        // link on next page and last page?
        if ($cur_page != $maximum) {
            $navbar['URL_NEXT'] = $url_start.($next < $last ? $next : $last);
            $navbar['URL_LAST'] = $url_start.$last;
        }

        // pages to display
        $navbar['pages'] = [];
        $navbar['pages'][1] = $url;
        for ($i = (int) max(floor($cur_page) - $pages_around, 2), $stop = (int) min(ceil($cur_page) + $pages_around + 1, $maximum);
            $i < $stop; $i++) {
            $navbar['pages'][$i] = $url.$start_str.(($i - 1) * $nb_element_page);
        }
        $navbar['pages'][(int)$maximum] = $url_start.$last;
        $navbar['NB_PAGE'] = $maximum;
    }
    return $navbar;
}

/**
 * return an array which will be sent to template to display recent icon
 *
 * @param string $date
 * @param bool $is_child_date
 * @return array<mixed>|false
 */
function get_icon(?string $date, bool $is_child_date = false): false|array
{
    if (empty($date)) {
        return false;
    }

    $raw = \Piwigo\Users\CurrentUser::get()->rawAttributes;
    $recentPeriod = is_scalar($raw['recent_period'] ?? null) ? (int) $raw['recent_period'] : 7;

    $title = \Piwigo\Cache\RequestCache::remember('get_icon', 'title', static fn () => l10n(
        'photos posted during the last %d days',
        $recentPeriod
    ));

    $icon = [
      'TITLE' => $title,
      'IS_CHILD_DATE' => $is_child_date,
      ];

    if (\Piwigo\Cache\RequestCache::has('get_icon', $date)) {
        return \Piwigo\Cache\RequestCache::get('get_icon', $date) ? $icon : [];
    }

    $sqlRecentDate = \Piwigo\Cache\RequestCache::remember(
        'get_icon',
        'sql_recent_date',
        static function () use ($recentPeriod): string {
            $v = get_dbal_connection()->executeQuery('SELECT '.\Piwigo\Db\SqlExpr::recentPeriodExpr((string) $recentPeriod))->fetchOne();
            return is_scalar($v) ? (string) $v : '';
        }
    );

    $isRecent = $date > $sqlRecentDate;
    \Piwigo\Cache\RequestCache::set('get_icon', $date, $isRecent);

    return $isRecent ? $icon : [];
}

/**
 * check token comming from form posted or get params to prevent csrf attacks.
 * if pwg_token is empty action doesn't require token
 * else pwg_token is compare to server token
 *
 * @return void access denied if token given is not equal to server token
 */
function check_pwg_token(): void
{
    if (!empty($_REQUEST['pwg_token'])) {
        if (get_pwg_token() != $_REQUEST['pwg_token']) {
            access_denied();
        }
    } else {
        bad_request('missing token');
    }
}

/**
 * get pwg_token used to prevent csrf attacks
 */
function get_pwg_token(): string
{
    return hash_hmac('md5', (string) session_id(), (string) \Piwigo\Config\Config::secretKey());
}

/*
 * breaks the script execution if the given value doesn't match the given
 * pattern. This should happen only during hacking attempts.
 *
 * @param string $param_name
 * @param array $param_array
 * @param boolean $is_array
 * @param string $pattern
 * @param boolean $mandatory
 */
/** @param array<mixed> $param_array */
function check_input_parameter(string $param_name, array $param_array, bool $is_array, ?string $pattern, bool $mandatory = false): bool
{
    $param_value = null;
    if (isset($param_array[$param_name])) {
        $param_value = $param_array[$param_name];
    }

    // it's ok if the input parameter is null
    if (empty($param_value)) {
        if ($mandatory) {
            fatal_error('[Hacking attempt] the input parameter "'.$param_name.'" is not valid');
        }
        return true;
    }

    if ($is_array) {
        if (!is_array($param_value)) {
            fatal_error('[Hacking attempt] the input parameter "'.$param_name.'" should be an array');
        }

        foreach ($param_value as $key => $item_to_check) {
            if (!preg_match(PATTERN_ID, (string) $key) or !preg_match($pattern ?? '', is_scalar($item_to_check) ? (string) $item_to_check : '')) {
                fatal_error('[Hacking attempt] an item is not valid in input parameter "'.$param_name.'"');
            }
        }
        return true;
    } else {
        if (!preg_match($pattern ?? '', is_scalar($param_value) ? (string) $param_value : '')) {
            fatal_error('[Hacking attempt] the input parameter "'.$param_name.'" is not valid');
        }
        return true;
    }
}

/**
 * get localized privacy level values
 *
 * @return string[]
 */
function get_privacy_level_options(): array
{
    $options = [];
    $label = '';
    foreach (array_reverse(\Piwigo\Config\Config::availablePermissionLevels()) as $level) {
        if (0 == $level) {
            $label = l10n('Everybody');
        } else {
            if (strlen($label)) {
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
 *
 * @return string
 */
function get_device()
{
    $device = pwg_get_session_var('device', '');

    if ($device === '') {
        $uagent_obj = new uagent_info();
        if ($uagent_obj->DetectSmartphone()) {
            $device = 'mobile';
        } elseif ($uagent_obj->DetectTierTablet()) {
            $device = 'tablet';
        } else {
            $device = 'desktop';
        }
        pwg_set_session_var('device', $device);
    }

    return $device;
}

/**
 * return true if mobile theme should be loaded
 *
 * @return bool
 */
function mobile_theme()
{
    if (empty(\Piwigo\Config\Config::mobilTheme())) {
        return false;
    }

    if (isset($_GET['mobile'])) {
        $is_mobile_theme = \Piwigo\Core\BoolUtil::fromMixed($_GET['mobile']);
        pwg_set_session_var('mobile_theme', $is_mobile_theme);
    } else {
        $is_mobile_theme = pwg_get_session_var('mobile_theme');
    }

    if (is_null($is_mobile_theme)) {
        $is_mobile_theme = (get_device() == 'mobile');
        pwg_set_session_var('mobile_theme', $is_mobile_theme);
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

    if (!str_starts_with($url, 'http://') and !str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * check email format
 *
 * @param string $mail_address
 */
function email_check_format($mail_address): bool
{
    return filter_var($mail_address, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * returns the number of available comments for the connected user
 *
 * @return int
 */
function get_nb_available_comments(): int
{
    $cached = \Piwigo\Cache\RequestCache::remember('user', 'nb_available_comments', static function () {
        $where = [];
        if (!is_admin()) {
            $where[] = 'validated=\'true\'';
        }
        $where[] = get_sql_condition_FandF(
            [
              'forbidden_categories' => 'category_id',
              'forbidden_images' => 'ic.image_id',
            ],
            '',
            true
        );

        $query = 'SELECT COUNT(DISTINCT(com.id)) FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic' .
            ' INNER JOIN ' . COMMENTS_TABLE . ' AS com ON ic.image_id = com.image_id' .
            ' WHERE ' . implode(' AND ', $where);
        $count = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchOne();
        $nb = is_numeric($count) ? (int) $count : 0;

        single_update(
            USER_CACHE_TABLE,
            ['nb_available_comments' => $nb],
            ['user_id' => \Piwigo\Users\CurrentUser::get()->id]
        );
        return $nb;
    });
    return is_int($cached) ? $cached : 0;
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
    $replace_chars   = (fn ($m): string => (string)ord(strtolower((string) $m[1])[0]));

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
    if (!\Piwigo\Config\Config::has('lounge_active') or !\Piwigo\Config\Config::loungeActive()) {
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
  FROM '.LOUNGE_TABLE.'
    JOIN '.IMAGES_TABLE.' ON image_id = id
  ORDER BY image_id ASC
  LIMIT 1
;';
    $voyagers = query2array($query);
    if (count($voyagers)) {
        $voyager = $voyagers[0];
        $age = strtotime((string) $voyager['dbnow']) - strtotime((string) $voyager['date_available']);

        if ($age > \Piwigo\Config\Config::loungeMaxDuration()) {
            include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
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
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $start_time = get_moment();

    if (!\Piwigo\Config\Config::sendPiwigoInfos()) {
        return;
    }

    // \Piwigo\Config\Config::sendPiwigoInfosLastNotice() has been loaded in include/common, maybe
    // a few seconds earlier, we need a refreshed value from the database. Another
    // concurrent execution might have already performed send_piwigo_infos 3 seconds ago.
    load_conf_from_db('param = "send_piwigo_infos_last_notice"', false);

    $do_send = false;
    if (\Piwigo\Config\Config::has('send_piwigo_infos_last_notice')) {
        $period = conf_get_param('send_piwigo_infos_period', 7 * 24 * 60 * 60);
        if (strtotime(\Piwigo\Config\Config::sendPiwigoInfosLastNotice() ?? '') < strtotime((is_scalar($period) ? (string) $period : '604800').' second ago')) {
            $do_send = true;
        }
    } else {
        $do_send = true;
    }

    if (!$do_send) {
        return;
    }

    $logger->info('['.__FUNCTION__.'] current conf.send_piwigo_infos_last_notice='.(\Piwigo\Config\Config::sendPiwigoInfosLastNotice() ?? 'notFound').' => lets do it');

    if (!pwg_is_dbconf_writeable()) {
        $logger->info('['.__FUNCTION__.'] conf is not writeable, abort');
        return;
    }

    $exec_id = pwg_unique_exec_begins('send_piwigo_infos');
    if (false === $exec_id) {
        $logger->info('['.__FUNCTION__.'] another execution is running, abort');
        return;
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $db_current_date = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    if (!\Piwigo\Config\Config::has('send_piwigo_infos_origin_hash')) {
        conf_update_param('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
    }

    [$container_type, $container_version] = get_container_info();

    $piwigo_infos = [
      'origin_hash' => \Piwigo\Config\Config::sendPiwigoInfosOriginHash(),
      'technical' => [
        'php_version' => PHP_VERSION,
        'piwigo_version' => PHPWG_VERSION,
        'os_version' => PHP_OS,
        'container_type' => $container_type,
        'container_version' => $container_version,
        'db_version' => \Piwigo\Db\DbInfo::version(),
        'php_datetime' => date('Y-m-d H:i:s'),
        'db_datetime' => $db_current_date,
        'graphics_library' => get_graphics_library(),
      ],
      'general_stats' => get_pwg_general_statitics(),
    ];


    // convert disk_usage from kB to mB
    $du = $piwigo_infos['general_stats']['disk_usage'] ?? 0;
    $piwigo_infos['general_stats']['disk_usage'] = intval((is_numeric($du) ? $du : 0) / 1024);

    $piwigo_infos['general_stats']['installed_on'] = get_installation_date();
    $piwigo_infos['general_stats']['nb_photos_synced'] = 0;
    $piwigo_infos['general_stats']['last_photo_synced'] = null;
    $piwigo_infos['general_stats']['last_photo'] = null;

    if ($piwigo_infos['general_stats']['nb_photos'] > 0) {
        $query = '
SELECT
    COUNT(*) AS counter
  FROM `'.IMAGES_TABLE.'`
  WHERE storage_category_id IS NOT NULL
;';
        if (query2array($query, null, 'counter')[0] > 0) {
            // slow SQL query, but necessary if you have files added by sync
            $query = '
SELECT
    IF(storage_category_id IS NULL, \'api\', \'sync\') AS add_method,
    MAX(date_available) AS last_added_on,
    COUNT(*) AS nb_files
  FROM `'.IMAGES_TABLE.'`
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
  FROM `'.IMAGES_TABLE.'`
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
  FROM `'.IMAGES_TABLE.'`
  GROUP BY ext
;';
        $piwigo_infos['file_extensions'] = query2array($query, 'ext');
    }

    // \Piwigo\Config\Config::override('pem_plugins_category', 12);
    // \Piwigo\Config\Config::override('pem_themes_category', 10);
    $url = PEM_URL . '/api/get_extension_list.php';
    $pem_extensions = fetchRemote($url, $result)
        ? safe_unserialize($result)
        : [];
    if ($pem_extensions !== []) {
        $official_exts = [];
        foreach ($pem_extensions as $eid => $ext) {
            if (is_array($ext) && !empty($ext['archive_root_dir'])) {
                $idxCat = $ext['idx_category'] ?? null;
                $archiveDir = $ext['archive_root_dir'];
                if (is_string($idxCat) || is_int($idxCat)) {
                    $official_exts[$idxCat][is_string($archiveDir) ? $archiveDir : ''] = $eid;
                }
            }
        }
    } else {
        $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] fetchRemote on '.$url.' has failed');
        send_piwigo_infos_retry_later(1 * 60 * 60); // 1 hour later
        pwg_unique_exec_ends('send_piwigo_infos');
        $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] executed in '.get_elapsed_time($start_time, get_moment()));
        return;
    }

    $plugins = new Plugins();
    $piwigo_infos['general_stats']['nb_private_plugins'] = 0;
    $piwigo_infos['plugins'] = [];
    foreach ($plugins->db_plugins_by_id as $plugin) {
        $pluginId = is_string($plugin['id'] ?? null) ? $plugin['id'] : '';
        $pluginState = is_string($plugin['state'] ?? null) ? $plugin['state'] : '';
        $pluginVersion = is_string($plugin['version'] ?? null) ? $plugin['version'] : '';
        if ('active' == $pluginState) {
            $eid = null;
            $fsPlugin = $plugins->fs_plugins[$pluginId] ?? null;
            if (is_array($fsPlugin)) {
                $uri = is_string($fsPlugin['uri'] ?? null) ? $fsPlugin['uri'] : '';
                if (preg_match('/eid=(\d+)/', $uri, $matches)) {
                    if (isset($pem_extensions[$matches[1]])) {
                        $eid = $matches[1];
                    }
                }
            }

            if (empty($eid)) {
                $eid = $official_exts[\Piwigo\Config\Config::pemPluginsCategory()][$pluginId] ?? null;
            }

            if (empty($eid)) {
                $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] '.$pluginId.' is a private plugin, not sent to piwigo.org');
                $piwigo_infos['general_stats']['nb_private_plugins']++;
                continue;
            }

            $pemExt = is_array($pem_extensions[$eid] ?? null) ? $pem_extensions[$eid] : [];
            $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $pluginId;

            $piwigo_infos['plugins'][] = '#'.(string)$eid.'/'.$codename.'/'.$pluginVersion;
        }
    }

    $piwigo_infos['general_stats']['nb_plugins'] = $piwigo_infos['general_stats']['nb_private_plugins'] + count($piwigo_infos['plugins']);

    $themes = new Themes();
    $piwigo_infos['general_stats']['nb_private_themes'] = 0;
    $piwigo_infos['themes'] = [];
    $private_themes = [];
    foreach ($themes->db_themes_by_id as $theme) {
        $themeId = is_string($theme['id'] ?? null) ? $theme['id'] : '';
        $themeVersion = is_string($theme['version'] ?? null) ? $theme['version'] : '';
        $eid = null;
        $fsTheme = $themes->fs_themes[$themeId] ?? null;
        if (is_array($fsTheme)) {
            $uri = is_string($fsTheme['uri'] ?? null) ? $fsTheme['uri'] : '';
            if (preg_match('/eid=(\d+)/', $uri, $matches)) {
                if (isset($pem_extensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
        }

        if (empty($eid)) {
            $eid = $official_exts[\Piwigo\Config\Config::pemThemesCategory()][$themeId] ?? null;
        }

        if (empty($eid)) {
            $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] '.$themeId.' is a private theme, not sent to piwigo.org');
            $private_themes[$themeId] = 1;
            continue;
        }

        $pemExt = is_array($pem_extensions[$eid] ?? null) ? $pem_extensions[$eid] : [];
        $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $themeId;

        $piwigo_infos['themes'][] = '#'.(string)$eid.'/'.$codename.'/'.$themeVersion;
    }

    $piwigo_infos['general_stats']['nb_private_themes'] = count(array_keys($private_themes));
    $piwigo_infos['general_stats']['nb_themes'] = $piwigo_infos['general_stats']['nb_private_themes'] + count($piwigo_infos['themes']);

    $default_theme = get_default_theme();
    if (isset($private_themes[$default_theme])) {
        $default_theme = 'private theme';
    }
    $piwigo_infos['general_stats']['default_theme'] = $default_theme;

    $piwigo_infos['themes_usage'] = [];
    $query = '
SELECT
    theme,
    COUNT(*) AS theme_counter
  FROM '.USER_INFOS_TABLE.'
  GROUP BY theme
  ORDER BY theme
;';
    $themes_used = query2array($query, 'theme', 'theme_counter');
    foreach ($themes_used as $theme_used => $counter) {
        if (isset($private_themes[$theme_used])) {
            $theme_used = 'private theme';
        }

        $piwigo_infos['themes_usage'][$theme_used] = ($piwigo_infos['themes_usage'][$theme_used] ?? 0) + $counter;
    }

    $piwigo_infos['general_stats']['default_language'] = get_default_language();

    $query = '
SELECT
    language,
    COUNT(*) AS language_counter
  FROM '.USER_INFOS_TABLE.'
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
  FROM '.ACTIVITY_TABLE.'
  WHERE object != \'system\'
  GROUP BY object, action
;';
    $activities = query2array($query);
    foreach ($activities as $activity) {
        $piwigo_infos['general_stats']['nb_activities'] += (int)$activity['counter'];
        $object_key = (string)$activity['object'];
        $action_key = (string)$activity['action'];
        if (!isset($piwigo_infos['activities'][$object_key])) {
            $piwigo_infos['activities'][$object_key] = [];
        }
        $piwigo_infos['activities'][$object_key][$action_key] = $activity['counter'];
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
  FROM '.ACTIVITY_TABLE.'
  WHERE object = \'system\'
  GROUP BY object, object_id, action
;';
    $activities = query2array($query);
    $system_activities = [];
    foreach ($activities as $activity) {
        $object_id_key = (int)$activity['object_id'];
        $action_key = (string)$activity['action'];
        $label_key = (string) ($label_for_system_object_id[$object_id_key] ?? 'undefined');
        if (!isset($system_activities[$label_key])) {
            $system_activities[$label_key] = [];
        }
        $system_activities[$label_key][$action_key] = $activity['counter'];
    }
    $piwigo_infos['activities']['system'] = $system_activities;

    $query = '
SELECT
    action,
    occured_on,
    details
  FROM '.ACTIVITY_TABLE.'
  WHERE object = \'system\'
    AND object_id = '.ACTIVITY_SYSTEM_CORE.'
    AND action IN (\'update\', \'autoupdate\')
  ORDER BY activity_id ASC
;';
    $updates = query2array($query);
    foreach ($updates as $update) {
        $details = safe_unserialize(is_string($update['details']) ? $update['details'] : '');
        if (isset($details['from_version']) and isset($details['to_version'])) {
            $piwigo_infos['updates'][] = [
              'action' => $update['action'],
              'occured_on' => $update['occured_on'],
              'from_version' => $details['from_version'],
              'to_version' => $details['to_version'],
            ];
        }
    }

    $watermark = ImageStdParams::get_watermark();

    $piwigo_infos['features'] = [
      'use_watermark' => !empty($watermark->file) ? 'yes' : 'no',
    ];

    // which remote apps have been used?
    $remote_apps_start_time = get_moment();

    $query = '
SELECT
    user_agent,
    COUNT(*) AS counter,
    MIN(occured_on) AS first_encounter,
    MAX(occured_on) AS last_encounter
  FROM '.ACTIVITY_TABLE.'
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
            if (preg_match($pattern, (string) $activity['user_agent'])) {
                $apps[$app_name]['counter'] = ($apps[$app_name]['counter'] ?? 0) + $activity['counter'];

                if (!isset($apps[$app_name]['first_encounter']) or strtotime($apps[$app_name]['first_encounter']) > strtotime((string) $activity['first_encounter'])) {
                    $apps[$app_name]['first_encounter'] = $activity['first_encounter'];
                }

                if (!isset($apps[$app_name]['last_encounter']) or strtotime($apps[$app_name]['last_encounter']) < strtotime((string) $activity['last_encounter'])) {
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
        $piwigo_infos['features'][$feature] = \Piwigo\Config\Config::raw($feature) ? 'yes' : 'no';
    }

    $updateUrl = conf_get_param('send_piwigo_infos_update_url', PHPWG_URL);
    $url = (is_scalar($updateUrl) ? (string) $updateUrl : PHPWG_URL).'/ws.php';

    $get_data = [
      'format' => 'php',
      'method' => 'porg.installs.update',
      'origin_hash' => $piwigo_infos['origin_hash'],
      ];

    $post_data = [
      'data' => json_encode($piwigo_infos),
      ];

    if (!fetchRemote($url, $result, $get_data, $post_data)) {
        $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] fetchRemote on '.$url.' method=porg.installs.update has failed');
        send_piwigo_infos_retry_later(24 * 60 * 60);
    } else {
        $last_notice = date('c');
        conf_update_param('send_piwigo_infos_last_notice', $last_notice, true);
        $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] fetchRemote success, new send_piwigo_infos_last_notice='.\Piwigo\Config\Config::sendPiwigoInfosLastNotice());
    }

    pwg_unique_exec_ends('send_piwigo_infos');
    $logger->info('['.__FUNCTION__.'][exec='.$exec_id.'] executed in '.get_elapsed_time($start_time, get_moment()));
}

function send_piwigo_infos_retry_later(int $wait_time): void
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    // let's fake a last_notice so that we only try 1 day later
    $last_notice = \Piwigo\Config\Config::has('send_piwigo_infos_last_notice') ? strtotime(\Piwigo\Config\Config::sendPiwigoInfosLastNotice() ?? '') : time();
    $last_notice += $wait_time;

    conf_update_param('send_piwigo_infos_last_notice', date('c', $last_notice), true);
    $logger->info('['.__FUNCTION__.'] new send_piwigo_infos_last_notice='.\Piwigo\Config\Config::sendPiwigoInfosLastNotice());
}

function pwg_unique_exec_begins(string $token_name, int $timeout = 60): false|string
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $exec_id = substr(sha1(random_bytes(1000)), 0, 8);
    $logger->info('['.$token_name.'][exec='.$exec_id.'] starts now');

    if (\Piwigo\Config\Config::has($token_name . '_running')) {
        $runningRaw = \Piwigo\Config\Config::raw($token_name . '_running');
        [$running_exec_id, $running_exec_start_time] = explode('-', is_scalar($runningRaw) ? (string) $runningRaw : '-');
        if (time() - (int)$running_exec_start_time > $timeout) {
            $logger->info('['.$token_name.'][exec='.$exec_id.'] exec='.$running_exec_id.', timeout stopped by another call to the function');
            pwg_unique_exec_ends($token_name);
        }
    }

    $conn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    $conn->executeStatement(
        'INSERT IGNORE INTO ' . CONFIG_TABLE . ' SET param=?, value=?',
        [$token_name . '_running', $exec_id . '-' . time()]
    );

    $running_exec = $conn->executeQuery(
        'SELECT value FROM ' . CONFIG_TABLE . ' WHERE param = ?',
        [$token_name . '_running']
    )->fetchOne();
    list($running_exec_id, ) = explode('-', is_scalar($running_exec) ? (string) $running_exec : '');

    if ($running_exec_id != $exec_id) {
        $logger->info('['.$token_name.'][exec='.$exec_id.'] skip');
        return false;
    }
    $logger->info('['.$token_name.'][exec='.$exec_id.'] wins the race and gets the token!');

    return $exec_id;
}

function pwg_unique_exec_is_running(string $token_name): bool
{
    $counter = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery(
            'SELECT COUNT(*) FROM ' . CONFIG_TABLE . ' WHERE param = ?',
            [$token_name . '_running']
        )
        ->fetchOne();

    return is_numeric($counter) ? (int) $counter > 0 : false;
}

function pwg_unique_exec_ends(string $token_name): void
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    conf_delete_param($token_name.'_running');
    $logger->info('['.$token_name.'] ends now');
}

/**
 *
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
 * @return array<mixed>
 */
/** @return array{0: string, 1: string|null} */
function get_container_info(): array
{
    // Check if OS is Linux and PHP doesn't restrict opening files
    if ((strtoupper(substr(PHP_OS, 0, 5)) === 'LINUX' and empty(ini_get('open_basedir')))) {
        if (file_exists('/proc/2/sched')) { // Check if PID2 exist
            $file = file_get_contents('/proc/2/sched'); // Read PID2 name
            if ($file and str_starts_with($file, 'kthreadd')) { // If PID 2 is kthreadd PHP is not running in a container
                return ['none', null];
            }
        }

        // PHP is running in a container, trying to determine container type
        $info_file_path = '/var/www/html/piwigo-docker.info';
        $info_file_linuxserver = '/build_version';

        // Check for official container tagfile
        if (is_readable($info_file_path)) {
            $file_lines = file($info_file_path);
            if (is_array($file_lines) and 'Official Piwigo container' === trim($file_lines[0])) {
                $container_version = null;
                // Take the last line and remove prefix (Build Version)
                if (preg_match('/^Build Version (.*)$/', $file_lines[count($file_lines) - 1], $matches)) {
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
                if (preg_match('/version:\s*(.*)$/', $file_lines[0], $matches)) {
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
    if ($date and $date->format($format) === $datetime) {
        return true;
    }

    // in case it fails, let's check with only date and no time
    $format = 'Y-m-d';
    $date = DateTime::createFromFormat($format, $datetime);
    if ($date and $date->format($format) === $datetime) {
        return true;
    }

    return false;
}
