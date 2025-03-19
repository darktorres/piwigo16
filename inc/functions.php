<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use DateInterval;
use DateMalformedStringException;
use DateTime;
use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_history;
use Piwigo\inc\dblayer\functions_mysqli;
use Random\RandomException;
use SmartyException;
use stdClass;
use uagent_info;

include_once(PHPWG_ROOT_PATH . 'inc/functions_plugins.php');
include_once(PHPWG_ROOT_PATH . 'inc/functions_user.php');
include_once(PHPWG_ROOT_PATH . 'inc/functions_session.php');

class functions
{
    /**
     * no option for mkgetdir()
     */
    public const MKGETDIR_NONE = 0;

    /**
     * sets mkgetdir() recursive
     */
    public const MKGETDIR_RECURSIVE = 1;

    /**
     * sets mkgetdir() exit script on error
     */
    public const MKGETDIR_DIE_ON_ERROR = 2;

    /**
     * sets mkgetdir() add a index.htm file
     */
    public const MKGETDIR_PROTECT_INDEX = 4;

    /**
     * sets mkgetdir() add a .htaccess file
     */
    public const MKGETDIR_PROTECT_HTACCESS = 8;

    /**
     * default options for mkgetdir() = MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX
     */
    public const MKGETDIR_DEFAULT = self::MKGETDIR_RECURSIVE | self::MKGETDIR_DIE_ON_ERROR | self::MKGETDIR_PROTECT_INDEX;

    /**
     * returns the current microsecond since Unix epoch
     *
     * @return string
     */
    public static function micro_seconds()
    {
        $t1 = explode(' ', microtime());
        $t2 = explode('.', $t1[0]);
        $t2 = $t1[1] . substr($t2[1], 0, 6);
        return $t2;
    }

    /**
     * returns a float value corresponding to the number of seconds since
     * the unix epoch (1st January 1970) and the microseconds are precised
     * e.g. 1052343429.89276600
     *
     * @return float
     */
    public static function get_moment()
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
    public static function get_elapsed_time($start, $end)
    {
        return number_format($end - $start, 3, '.', ' ') . ' s';
    }

    /**
     * returns the part of the string after the last "."
     *
     * @param string $filename
     * @return string
     */
    public static function get_extension($filename)
    {
        return substr(strrchr($filename, '.'), 1, strlen($filename));
    }

    /**
     * returns the part of the string before the last ".".
     * get_filename_wo_extension( 'test.tar.gz' ) = 'test.tar'
     *
     * @param string $filename
     * @return string
     */
    public static function get_filename_wo_extension($filename)
    {
        $pos = strrpos($filename, '.');
        return ($pos === false) ? $filename : substr($filename, 0, $pos);
    }

    /**
     * creates directory if not exists and ensures that directory is writable
     *
     * @param string $dir
     * @param int $flags combination of MKGETDIR_xxx
     * @return bool
     */
    public static function mkgetdir($dir, $flags = self::MKGETDIR_DEFAULT)
    {
        if (! is_dir($dir)) {
            global $conf;

            if (substr(PHP_OS, 0, 3) == 'WIN') {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }

            $umask = umask(0);
            $mkd = mkdir($dir, $conf['chmod_value'], ($flags & self::MKGETDIR_RECURSIVE) ? true : false);
            umask($umask);

            if ($mkd == false) {
                if ($flags & self::MKGETDIR_DIE_ON_ERROR) {
                    functions_html::fatal_error("{$dir} " . self::l10n('no write access'));
                }

                return false;
            }

            if ($flags & self::MKGETDIR_PROTECT_HTACCESS) {
                $file = $dir . '/.htaccess';

                if (! file_exists($file)) {
                    file_put_contents($file, 'deny from all');
                }
            }

            if ($flags & self::MKGETDIR_PROTECT_INDEX) {
                $file = $dir . '/index.htm';

                if (! file_exists($file)) {
                    file_put_contents($file, 'Not allowed!');
                }
            }
        }

        if (! is_writable($dir)) {
            if ($flags & self::MKGETDIR_DIE_ON_ERROR) {
                functions_html::fatal_error("{$dir} " . self::l10n('no write access'));
            }

            return false;
        }

        return true;
    }

    /**
     * finds out if a string is in ASCII, UTF-8 or other encoding
     *
     * @param string $Str
     * @return int *0* if _$str_ is ASCII, *1* if UTF-8, *-1* otherwise
     */
    public static function qualify_utf8($Str)
    {
        $ret = 0;

        for ($i = 0; $i < strlen($Str); $i++) {
            if (ord($Str[$i]) < 0x80) {
                continue;
                # 0bbbbbbb
            }

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
                # Does not match any model
            }

            for ($j = 0; $j < $n; $j++) { # n bytes matching 10bbbbbb follow ?
                if (++$i == strlen($Str) ||
                   (ord($Str[$i]) & 0xC0) != 0x80
                ) {
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
    public static function remove_accents($string)
    {
        $utf = self::qualify_utf8($string);

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
            $double_chars['in'] = [chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254)];
            $double_chars['out'] = ['OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th'];
            $string = str_replace($double_chars['in'], $double_chars['out'], $string);
        }

        return $string;
    }

    /**
     * removes accents from a string and converts it to lower case
     *
     * @param string $term
     * @return string
     */
    public static function pwg_transliterate($term)
    {
        if (function_exists('mb_strtolower') &&
            defined('PWG_CHARSET')
        ) {
            return self::remove_accents(mb_strtolower($term, PWG_CHARSET));
        }

        return self::remove_accents(strtolower($term));
    }

    /**
     * simplify a string to insert it into an URL
     *
     * @param string $str
     * @return string
     */
    public static function str2url($str)
    {
        $str = $safe = self::pwg_transliterate($str);
        $str = preg_replace('/[^\x80-\xffa-z0-9_\s\'\:\/\[\],-]/', '', $str);
        $str = preg_replace('/[\s\'\:\/\[\],-]+/', ' ', trim($str));
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
    public static function get_languages()
    {
        $query = <<<SQL
            SELECT id, name
            FROM languages
            ORDER BY name ASC;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        $languages = [];

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $row['id'])) {
                $languages[$row['id']] = $row['name'];
            }
        }

        return $languages;
    }

    /**
     * Does the current user must log visits in history table
     *
     * @param int $image_id
     * @param string $image_type
     * @return bool
     */
    public static function do_log($image_id = null, $image_type = null)
    {
        global $conf;

        $do_log = $conf['log'];

        if (functions_user::is_admin()) {
            $do_log = $conf['history_admin'];
        }

        if (functions_user::is_a_guest()) {
            $do_log = $conf['history_guest'];
        }

        $do_log = functions_plugins::trigger_change('pwg_log_allowed', $do_log, $image_id, $image_type);

        return $do_log;
    }

    /**
     * log the visit into history table
     *
     * @param int $image_id
     * @param string $image_type
     * @return bool
     */
    public static function pwg_log($image_id = null, $image_type = null, $format_id = null)
    {
        global $conf, $user, $page;

        $update_last_visit = false;

        if (empty($user['last_visit']) or
            strtotime($user['last_visit']) < time() - $conf['session_length']
        ) {
            $update_last_visit = true;
        }

        $update_last_visit = functions_plugins::trigger_change('pwg_log_update_last_visit', $update_last_visit);

        if ($update_last_visit) {
            $query = <<<SQL
                UPDATE user_infos
                SET last_visit = NOW(), lastmodified = lastmodified
                WHERE user_id = {$user['id']};
                SQL;
            functions_mysqli::pwg_query($query);
        }

        if (! self::do_log($image_id, $image_type)) {
            return false;
        }

        $tags_string = null;
        if ($page['section'] == 'tags') {
            $tags_string = implode(',', $page['tag_ids']);

            if (strlen($tags_string) > 50) {
                // we need to truncate, mysql won't accept a too long string
                $tags_string = substr($tags_string, 0, 50);
                // the last tag_id may have been truncated itself, so we must remove it
                $tags_string = substr($tags_string, 0, strrpos($tags_string, ','));
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'];
        // In case of "too long" ipv6 address, we take only the 15 first chars.
        //
        // It would be "cleaner" to increase length of history.IP to 50 chars, but
        // the alter table is very long on such a big table. We should plan this
        // for a future version, once history table is kept "smaller".
        if (strpos($ip, ':') !== false and
            strlen($ip) > 15
        ) {
            $ip = substr($ip, 0, 15);
        }

        // If plugin developers add their own sections, Piwigo will automatically add it in the history.section enum column
        if (isset($page['section'])) {
            // set cache if not available
            if (! isset($conf['history_sections_cache'])) {
                self::conf_update_param('history_sections_cache', functions_mysqli::get_enums('history', 'section'), true);
            }

            $conf['history_sections_cache'] = self::safe_unserialize($conf['history_sections_cache']);

            if (in_array($page['section'], $conf['history_sections_cache']) or
                in_array(strtolower($page['section']), array_map(strtolower(...), $conf['history_sections_cache']))
            ) {
                $section = $page['section'];
            } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $page['section'])) {
                $history_sections = functions_mysqli::get_enums('history', 'section');
                $history_sections[] = $page['section'];

                // alter history table structure, to include a new section
                functions_mysqli::pwg_query('ALTER TABLE history CHANGE section section enum(\'' . implode("','", array_unique($history_sections)) . '\') DEFAULT NULL;');

                // and refresh cache
                self::conf_update_param('history_sections_cache', functions_mysqli::get_enums('history', 'section'), true);

                $section = $page['section'];
            }
        }

        $sectionValue = isset($section) ? "'{$section}'" : 'NULL';
        $categoryIdValue = $page['category']['id'] ?? 'NULL';
        $searchIdValue = $page['search_id'] ?? 'NULL';
        $imageIdValue = $image_id ?? 'NULL';
        $imageTypeValue = isset($image_type) ? "'{$image_type}'" : 'NULL';
        $formatIdValue = $format_id ?? 'NULL';
        $authKeyIdValue = $page['auth_key_id'] ?? 'NULL';
        $tagsStringValue = isset($tags_string) ? "'{$tags_string}'" : 'NULL';
        $query = <<<SQL
            INSERT INTO history
                (date, time, user_id, IP, section, category_id, search_id, image_id, image_type, format_id, auth_key_id, tag_ids)
            VALUES
                (CURRENT_DATE, CURRENT_TIME, {$user['id']}, '{$ip}', {$sectionValue}, {$categoryIdValue}, {$searchIdValue},
                {$imageIdValue}, {$imageTypeValue}, {$formatIdValue}, {$authKeyIdValue}, {$tagsStringValue});
            SQL;
        functions_mysqli::pwg_query($query);

        $history_id = functions_mysqli::pwg_db_insert_id('history');

        if ($history_id % 1000 == 0) {
            include_once(PHPWG_ROOT_PATH . 'admin/inc/functions_history.php');
            functions_history::history_summarize(50000);
        }

        if ($conf['history_autopurge_every'] > 0 and
            $history_id % $conf['history_autopurge_every'] == 0
        ) {
            include_once(PHPWG_ROOT_PATH . 'admin/inc/functions_history.php');
            functions_history::history_autopurge();
        }

        return true;
    }

    public static function pwg_activity($object, $object_id, $action, $details = [])
    {
        global $user;

        // in case of uploadAsync, do not log the automatic login as an independent activity
        if (isset($_REQUEST['method']) and
            $_REQUEST['method'] == 'pwg.images.uploadAsync' and
            $action == 'login'
        ) {
            return;
        }

        if (isset($_REQUEST['method']) and
            $_REQUEST['method'] == 'pwg.plugins.performAction' and
            $_REQUEST['action'] != $action
        ) {
            // for example, if you "restore" a plugin, the internal sequence will perform deactivate/uninstall/install/activate.
            // We only want to keep the last call to pwg_activity with the "restore" action.
            return;
        }

        $object_ids = $object_id;

        if (! is_array($object_id)) {
            $object_ids = [$object_id];
        }

        if (isset($_REQUEST['method'])) {
            $details['method'] = $_REQUEST['method'];
        } else {
            $details['script'] = self::script_basename();

            if ($details['script'] == 'admin' and
                isset($_GET['page'])
            ) {
                $details['script'] .= '/' . $_GET['page'];
            }
        }

        if ($action == 'autoupdate') {
            // autoupdate on a plugin can happen anywhere, the "script/method" is not meaningful
            unset($details['method']);
            unset($details['script']);
        }

        $user_agent = null;

        if ($object == 'user' and
            $action == 'login' and
            isset($_SERVER['HTTP_USER_AGENT'])
        ) {
            $user_agent = strip_tags($_SERVER['HTTP_USER_AGENT']);
        }

        if ($object == 'photo' and
            $action == 'add' and
            ! isset($details['sync'])
        ) {
            $details['added_with'] = 'app';

            if (isset($_SERVER['HTTP_REFERER']) and
                preg_match('/page=photos_add/', $_SERVER['HTTP_REFERER'])
            ) {
                $details['added_with'] = 'browser';
            }
        }

        if (in_array($object, ['album', 'photo']) and
            $action == 'delete' and
            isset($_GET['page']) and
            $_GET['page'] == 'site_update'
        ) {
            $details['sync'] = true;
        }

        if ($object == 'tag' and
            $action == 'delete' and
            isset($_POST['destination_tag'])
        ) {
            $details['action'] = 'merge';
            $details['destination_tag'] = $_POST['destination_tag'];
        }

        $inserts = [];
        $details_insert = functions_mysqli::pwg_db_real_escape_string(serialize($details));
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $session_id = ! empty(session_id()) ? session_id() : 'none';

        foreach ($object_ids as $loop_object_id) {
            $performed_by = $user['id'] ?? 0; // on a plugin autoupdate, $user is not yet loaded

            if ($action == 'logout') {
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
                'user_agent' => functions_mysqli::pwg_db_real_escape_string($user_agent),
            ];
        }

        functions_mysqli::mass_inserts('activity', array_keys($inserts[0]), $inserts);
    }

    /**
     * Computes the difference between two dates.
     * returns a DateInterval object or a stdClass with the same attributes
     * http://stephenharris.info/date-intervals-in-php-5-2
     *
     * @param DateTime $date1
     * @param DateTime $date2
     * @return DateInterval|stdClass
     * @throws DateMalformedStringException
     */
    public static function dateDiff($date1, $date2)
    {
        if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
            return $date1->diff($date2);
        }

        $diff = new stdClass();

        //Make sure $date1 is earlier
        $diff->invert = $date2 < $date1;

        if ($diff->invert) {
            list($date1, $date2) = [$date2, $date1];
        }

        //Calculate R values
        $R = ($date1 <= $date2 ? '+' : '-');
        $r = ($date1 <= $date2 ? '' : '-');

        //Calculate total days
        $diff->days = round(abs($date1->format('U') - $date2->format('U')) / 86400);

        //A leap year work around - consistent with DateInterval
        $leap_year = $date1->format('m-d') == '02-29';

        if ($leap_year) {
            $date1->modify('-1 day');
        }

        //Years, months, days, hours
        $periods = [
            'years' => -1,
            'months' => -1,
            'days' => -1,
            'hours' => -1,
        ];

        foreach ($periods as $period => &$i) {
            if ($period == 'days' &&
                $leap_year
            ) {
                $date1->modify('+1 day');
            }

            while ($date1 <= $date2) {
                $date1->modify('+1 ' . $period);
                $i++;
            }

            //Reset date and record increments
            $date1->modify('-1 ' . $period);
        }

        list($diff->y, $diff->m, $diff->d, $diff->h) = array_values($periods);

        //Minutes, seconds
        $diff->s = round(abs($date1->format('U') - $date2->format('U')));
        $diff->i = floor($diff->s / 60);
        $diff->s = $diff->s - $diff->i * 60;

        return $diff;
    }

    /**
     * converts a string into a DateTime object
     *
     * @param int|string $original timestamp or datetime string
     * @param string $format input format respecting date() syntax
     * @return DateTime|false
     * @throws DateMalformedStringException
     */
    public static function str2DateTime($original, $format = null)
    {
        if (empty($original)) {
            return false;
        }

        if ($original instanceof DateTime) {
            return $original;
        }

        if (! empty($format) &&
            version_compare(PHP_VERSION, '5.3.0') >= 0
        ) { // from known date format
            return DateTime::createFromFormat('!' . $format, $original); // ! char to reset fields to UNIX epoch
        }

        $t = trim($original, '0123456789');

        if (empty($t)) { // from timestamp
            return new DateTime('@' . $original);
        }

        // from unknown date format (assuming something like Y-m-d H:i:s)

        $ymdhms = [];
        $tok = strtok($original, '- :/');

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
        $date->setDate($ymdhms[0], $ymdhms[1], $ymdhms[2]);
        $date->setTime($ymdhms[3], $ymdhms[4], $ymdhms[5]);
        return $date;
    }

    /**
     * returns a formatted and localized date for display
     *
     * @param int|string $original timestamp or datetime string
     * @param array $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
     *    THIS PARAMETER IS PLANNED TO CHANGE
     * @param string $format input format respecting date() syntax
     * @return string
     * @throws DateMalformedStringException
     */
    public static function format_date($original, $show = null, $format = null)
    {
        global $lang;

        $date = self::str2DateTime($original, $format);

        if (! $date) {
            return self::l10n('N/A');
        }

        if ($show === null ||
            $show === true
        ) {
            $show = ['day_name', 'day', 'month', 'year'];
        }

        // TODO use IntlDateFormatter for proper i18n

        $print = '';

        if (in_array('day_name', $show)) {
            $print .= $lang['day'][$date->format('w')] . ' ';
        }

        if (in_array('day', $show)) {
            $print .= $date->format('j') . ' ';
        }

        if (in_array('month', $show)) {
            $print .= $lang['month'][$date->format('n')] . ' ';
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
     * Format a "From ... to ..." string from two dates
     * @param string $from
     * @param string $to
     * @param bool $full
     * @return string
     * @throws DateMalformedStringException
     */
    public static function format_fromto($from, $to, $full = false)
    {
        $from = self::str2DateTime($from);
        $to = self::str2DateTime($to);

        if ($from->format('Y-m-d') == $to->format('Y-m-d')) {
            return self::format_date($from);
        }

        if ($full ||
            $from->format('Y') != $to->format('Y')
        ) {
            $from_str = self::format_date($from);
        } elseif ($from->format('m') != $to->format('m')) {
            $from_str = self::format_date($from, ['day_name', 'day', 'month']);
        } else {
            $from_str = self::format_date($from, ['day_name', 'day']);
        }

        $to_str = self::format_date($to);

        return self::l10n('from %s to %s', $from_str, $to_str);
    }

    /**
     * Works out the time since the given date
     *
     * @param int|string $original timestamp or datetime string
     * @param string $stop year,month,week,day,hour,minute,second
     * @param string $format input format respecting date() syntax
     * @param bool $with_text append "ago" or "in the future"
     * @param bool $with_week
     * @return string
     * @throws DateMalformedStringException
     */
    public static function time_since($original, $stop = 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = false)
    {
        $date = self::str2DateTime($original, $format);

        if (! $date) {
            return self::l10n('N/A');
        }

        $now = new DateTime();
        $diff = self::dateDiff($now, $date);

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
                    $print .= ' ' . self::l10n_dec('%d ' . $name, '%d ' . $name . 's', $value);
                }

                if (! empty($print) &&
                    $i >= $j
                ) {
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
                    $print = self::l10n_dec('%d ' . $name, '%d ' . $name . 's', $value);
                }

                if (! empty($print) &&
                    $i >= $j
                ) {
                    break;
                }

                $i++;
            }
        }

        $print = trim($print);

        if ($with_text) {
            if ($diff->invert) {
                $print = self::l10n('%s ago', $print);
            } else {
                $print = self::l10n('%s in the future', $print);
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
     * @throws DateMalformedStringException
     */
    public static function transform_date($original, $format_in, $format_out, $default = null)
    {
        if (empty($original)) {
            return $default;
        }

        $date = self::str2DateTime($original, $format_in);
        return $date->format($format_out);
    }

    /**
     * append a variable to _$debug_ global
     *
     * @param string $string
     */
    public static function pwg_debug($string)
    {
        global $debug,$t2,$page;

        $now = explode(' ', microtime());
        $now2 = explode('.', $now[0]);
        $now2 = $now[1] . '.' . $now2[1];
        $time = number_format($now2 - $t2, 3, '.', ' ') . ' s';
        $debug .= '<p>';
        $debug .= '[' . $time . ', ';
        $debug .= $page['count_queries'] . ' queries] : ' . $string;
        $debug .= "</p>\n";
    }

    /**
     * Redirects to the given URL (HTTP method).
     * once this function called, the execution doesn't go further
     * (presence of an exit() instruction.
     *
     * @param string $url
     */
    public static function redirect_http($url)
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
     * once this function called, the execution doesn't go further
     * (presence of an exit() instruction.
     *
     * @param string $url
     * @param string $msg
     * @param int $refresh_time
     * @throws SmartyException
     */
    public static function redirect_html($url, $msg = '', $refresh_time = 0)
    {
        global $user, $template, $lang_info, $conf, $lang, $t2, $page, $debug;

        if (! isset($lang_info) ||
            ! isset($template)
        ) {
            $user = functions_user::build_user($conf['guest_id'], true);
            self::load_language('common.lang');
            functions_plugins::trigger_notify('loading_lang');
            self::load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template(PHPWG_ROOT_PATH . 'themes', functions_user::get_default_theme());
        } elseif (defined('IN_ADMIN') and
                  IN_ADMIN
        ) {
            $template = new Template(PHPWG_ROOT_PATH . 'themes', functions_user::get_default_theme());
        }

        if (empty($msg)) {
            $msg = nl2br(self::l10n('Redirection...'));
        }

        $refresh = $refresh_time;
        $url_link = $url;
        $title = 'redirection';

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);

        include(PHPWG_ROOT_PATH . 'inc/page_header.php');

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);
        $template->assign('REDIRECT_MSG', $msg);

        $template->parse('redirect');

        include(PHPWG_ROOT_PATH . 'inc/page_tail.php');

        exit();
    }

    /**
     * Redirects to the given URL (automatically choose HTTP or HTML method).
     * once this function called, the execution doesn't go further
     * (presence of an exit() instruction.
     *
     * @param string $url
     * @param string $msg
     * @param int $refresh_time
     * @throws SmartyException
     */
    public static function redirect($url, $msg = '', $refresh_time = 0)
    {
        global $conf;

        // with RefreshTime != 0, only html must be used
        if ($conf['default_redirect_method'] == 'http' and
            $refresh_time == 0 and
            ! headers_sent()
        ) {
            self::redirect_http($url);
        } else {
            self::redirect_html($url, $msg, $refresh_time);
        }
    }

    /**
     * returns available themes
     *
     * @param bool $show_mobile
     * @return array
     */
    public static function get_pwg_themes($show_mobile = false)
    {
        global $conf;

        $themes = [];

        $query = <<<SQL
            SELECT id, name
            FROM themes
            ORDER BY name ASC;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if ($row['id'] == $conf['mobile_theme']) {
                if (! $show_mobile) {
                    continue;
                }

                $row['name'] .= ' (' . self::l10n('Mobile') . ')';
            }

            if (self::check_theme_installed($row['id'])) {
                $themes[$row['id']] = $row['name'];
            }
        }

        // plugins want remove some themes based on user status maybe?
        $themes = functions_plugins::trigger_change('get_pwg_themes', $themes);

        return $themes;
    }

    /**
     * check if a theme is installed (directory exists)
     *
     * @param string $theme_id
     * @return bool
     */
    public static function check_theme_installed($theme_id)
    {
        global $conf;

        return file_exists($conf['themes_dir'] . '/' . $theme_id . '/themeconf.php');
    }

    /**
     * Transforms an original path to its pwg representative
     *
     * @param string $path
     * @param string $representative_ext
     * @return string
     */
    public static function original_to_representative($path, $representative_ext)
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
     * @return string
     */
    public static function original_to_format($path, $format_ext)
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
    public static function get_element_path($element_info)
    {
        $path = $element_info['path'];

        if (! functions_url::url_is_remote($path)) {
            $path = PHPWG_ROOT_PATH . $path;
        }

        return $path;
    }

    /**
     * fill the current user caddie with given elements, if not already in caddie
     *
     * @param int[] $elements_id
     */
    public static function fill_caddie($elements_id)
    {
        global $user;

        $query = <<<SQL
            SELECT element_id
            FROM caddie
            WHERE user_id = {$user['id']};
            SQL;
        $in_caddie = functions_mysqli::query2array($query, null, 'element_id');

        $caddiables = array_diff($elements_id, $in_caddie);

        $datas = [];

        foreach ($caddiables as $caddiable) {
            $datas[] = [
                'element_id' => $caddiable,
                'user_id' => $user['id'],
            ];
        }

        if (count($caddiables) > 0) {
            functions_mysqli::mass_inserts('caddie', ['element_id', 'user_id'], $datas);
        }
    }

    /**
     * returns the element name from its filename.
     * removes file extension and replace underscores by spaces
     *
     * @param string $filename
     * @return string name
     */
    public static function get_name_from_file($filename)
    {
        return str_replace('_', ' ', self::get_filename_wo_extension($filename));
    }

    /**
     * translation function.
     * returns the corresponding value from _$lang_ if existing else the key is returned
     * if more than one parameter is provided sprintf is applied
     *
     * @param string $key
     * @return string
     */
    public static function l10n($key)
    {
        global $lang, $conf;

        $val = $lang[$key];

        if ($val === null) {
            if ($conf['debug_l10n'] and
                ! isset($lang[$key]) and
                ! empty($key)
            ) {
                trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
            }

            $val = $key;
        }

        if (func_num_args() > 1) {
            $args = func_get_args();
            $val = vsprintf($val, array_slice($args, 1));
        }

        return $val;
    }

    /**
     * returns the printf value for strings including %d
     * returned value is concorded with decimal value (singular, plural)
     *
     * @param string $singular_key
     * @param string $plural_key
     * @param int $decimal
     * @return string
     */
    public static function l10n_dec($singular_key, $plural_key, $decimal)
    {
        global $lang_info;

        return sprintf(
            self::l10n((
                ($decimal > 1 or ($decimal == 0 and $lang_info['zero_plural']))
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
     * @param mixed $args arguments to use on sprintf($key, args)
     *   if args is a array, each values are used on sprintf
     * @return array[]
     */
    public static function get_l10n_args($key, $args = '')
    {
        if (is_array($args)) {
            $key_arg = array_merge([$key], $args);
        } else {
            $key_arg = [$key, $args];
        }

        return [
            'key_args' => $key_arg,
        ];
    }

    /**
     * returns a string formated with l10n elements.
     * it is useful to "prepare" a text and translate it later
     * @see get_l10n_args()
     *
     * @param array $key_args one l10n_args element or array of l10n_args elements
     * @param string $sep used when translated elements are concatenated
     * @return string
     */
    public static function l10n_args($key_args, $sep = "\n")
    {
        if (is_array($key_args)) {
            foreach ($key_args as $key => $element) {
                if (isset($result)) {
                    $result .= $sep;
                } else {
                    $result = '';
                }

                if ($key === 'key_args') {
                    array_unshift($element, self::l10n(array_shift($element))); // translate the key
                    $result .= call_user_func_array(sprintf(...), $element);
                } else {
                    $result .= self::l10n_args($element, $sep);
                }
            }
        } else {
            functions_html::fatal_error('l10n_args: Invalid arguments');
        }

        return $result;
    }

    /**
     * returns the corresponding value from $themeconf if existing or an empty string
     *
     * @param string $key
     * @return string
     */
    public static function get_themeconf($key)
    {
        return $GLOBALS['template']->get_themeconf($key);
    }

    /**
     * Returns webmaster mail address depending on $conf['webmaster_id']
     *
     * @return string
     */
    public static function get_webmaster_mail_address()
    {
        global $conf;

        $query = <<<SQL
            SELECT {$conf['user_fields']['email']}
            FROM users
            WHERE {$conf['user_fields']['id']} = {$conf['webmaster_id']};
            SQL;
        list($email) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        $email = functions_plugins::trigger_change('get_webmaster_mail_address', $email);

        return $email;
    }

    /**
     * Add configuration parameters from database to global $conf array
     *
     * @param string $condition SQL condition
     */
    public static function load_conf_from_db($condition = '')
    {
        global $conf;

        $condition = ! empty($condition) ? "WHERE {$condition}" : '';
        $query = <<<SQL
            SELECT param, value
            FROM config
            {$condition}
            SQL;
        $query = trim($query) . ';';
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0 and
            ! empty($condition)
        ) {
            functions_html::fatal_error('No configuration data');
        }

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $val = isset($row['value']) ? $row['value'] : '';

            // If the field is true or false, the variable is transformed into a boolean value.
            if ($val == 'true') {
                $val = true;
            } elseif ($val == 'false') {
                $val = false;
            }

            $conf[$row['param']] = $val;
        }

        functions_plugins::trigger_notify('load_conf', $condition);
    }

    /**
     * Is the config table currently writeable?
     *
     * @return bool
     * @throws RandomException
     */
    public static function pwg_is_dbconf_writeable()
    {
        list($param, $value) = ['pwg_is_dbconf_writeable_' . functions_session::generate_key(12), date('c') . ' ' . functions_session::generate_key(20)];

        self::conf_update_param($param, $value);
        list($dbvalue) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query("SELECT value FROM config WHERE param = '{$param}';"));

        if ($dbvalue != $value) {
            return false;
        }

        self::conf_delete_param($param);
        return true;
    }

    /**
     * Add or update a config parameter
     *
     * @param string $param
     * @param string $value
     * @param bool $updateGlobal update global *$conf* variable
     * @param callable $parser function to apply to the value before save in database
     *     (eg: serialize, json_encode) will not be applied to *$conf* if *$parser* is *true*
     */
    public static function conf_update_param($param, $value, $updateGlobal = false, $parser = null)
    {
        if ($parser != null) {
            $dbValue = call_user_func($parser, $value);
        } elseif (is_array($value) ||
                  is_object($value)
        ) {
            $dbValue = addslashes(serialize($value));
        } else {
            $dbValue = functions_mysqli::boolean_to_string($value);
        }

        $query = <<<SQL
            INSERT INTO config
                (param, value)
            VALUES
                ('{$param}', '{$dbValue}')
            ON DUPLICATE KEY UPDATE
                value = '{$dbValue}';
            SQL;

        functions_mysqli::pwg_query($query);

        if ($updateGlobal) {
            global $conf;
            $conf[$param] = $value;
        }
    }

    /**
     * Delete one or more config parameters
     *
     * @param string|string[] $params
     */
    public static function conf_delete_param($params)
    {
        global $conf;

        if (! is_array($params)) {
            $params = [$params];
        }

        if (empty($params)) {
            return;
        }

        $implodedParams = implode("','", $params);
        $query = <<<SQL
            DELETE FROM config
            WHERE param IN ('{$implodedParams}');
            SQL;
        functions_mysqli::pwg_query($query);

        foreach ($params as $param) {
            unset($conf[$param]);
        }
    }

    /**
     * Return a default value for a configuration parameter.
     *
     * @param string $param the configuration value to be extracted (if it exists)
     * @param mixed $default_value the default value for the configuration value if it does not exist.
     * @return mixed The configuration value if the variable exists, otherwise the default.
     */
    public static function conf_get_param($param, $default_value = null)
    {
        global $conf;

        if (isset($conf[$param])) {
            return $conf[$param];
        }

        return $default_value;
    }

    /**
     * Apply *unserialize* on a value only if it is a string
     *
     * @param array|string $value
     * @return array
     */
    public static function safe_unserialize($value)
    {
        if (is_string($value)) {
            return unserialize($value);
        }

        return $value;
    }

    /**
     * Apply *json_decode* on a value only if it is a string
     *
     * @param array|string $value
     * @return array
     */
    public static function safe_json_decode($value)
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    /**
     * Prepends and appends strings at each value of the given array.
     *
     * @param array $array
     * @param string $prepend_str
     * @param string $append_str
     * @return array
     */
    public static function prepend_append_array_items($array, $prepend_str, $append_str)
    {
        array_walk($array, function (&$value, $key) use ($prepend_str, $append_str) { $value = "{$prepend_str}{$value}{$append_str}"; });
        return $array;
    }

    /**
     * creates an simple hashmap based on a SQL query.
     * choose one to be the key, another one to be the value.
     * @deprecated 2.6
     *
     * @param string $query
     * @param string $keyname
     * @param string $valuename
     * @return array
     */
    public static function simple_hash_from_query($query, $keyname, $valuename)
    {
        return functions_mysqli::query2array($query, $keyname, $valuename);
    }

    /**
     * creates an associative array based on a SQL query.
     * choose one to be the key
     * @deprecated 2.6
     *
     * @param string $query
     * @param string $keyname
     * @return array
     */
    public static function hash_from_query($query, $keyname)
    {
        return functions_mysqli::query2array($query, $keyname);
    }

    /**
     * creates a numeric array based on a SQL query.
     * if _$fieldname_ is empty the returned value will be an array of arrays
     * if _$fieldname_ is provided the returned value will be a one dimension array
     * @deprecated 2.6
     *
     * @param string $query
     * @param string $fieldname
     * @return array
     */
    public static function array_from_query($query, $fieldname = false)
    {
        if ($fieldname === false) {
            return functions_mysqli::query2array($query);
        }

        return functions_mysqli::query2array($query, null, $fieldname);
    }

    /**
     * Return the basename of the current script.
     * The lowercase case filename of the current script without extension
     *
     * @return string
     */
    public static function script_basename()
    {
        global $conf;

        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $value) {
            if (! empty($_SERVER[$value])) {
                $filename = strtolower($_SERVER[$value]);

                if ($conf['php_extension_in_urls'] and
                    self::get_extension($filename) !== 'php'
                ) {
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
    public static function get_filter_page_value($value_name)
    {
        global $conf;

        $page_name = self::script_basename();

        if (isset($conf['filter_pages'][$page_name][$value_name])) {
            return $conf['filter_pages'][$page_name][$value_name];
        } elseif (isset($conf['filter_pages']['default'][$value_name])) {
            return $conf['filter_pages']['default'][$value_name];
        }

        return null;
    }

    /**
     * return the character set used by Piwigo
     * @return string
     */
    public static function get_pwg_charset()
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
     *
     * @param string $lang_id
     * @return string|null
     */
    public static function get_parent_language($lang_id = null)
    {
        if (empty($lang_id)) {
            global $lang_info;
            return ! empty($lang_info['parent']) ? $lang_info['parent'] : null;
        }

        $f = PHPWG_ROOT_PATH . 'language/' . $lang_id . '/common.lang.php';

        if (file_exists($f)) {
            include($f);
            return ! empty($lang_info['parent']) ? $lang_info['parent'] : null;
        }

        return null;
    }

    /**
     * includes a language file or returns the content of a language file
     *
     * tries to load in descending order:
     *   param language, user language, default language
     *
     * @param string $filename
     * @param string $dirname
     * @param array{
     *     language: string,
     *     return: bool,
     *     no_fallback: bool,
     *     force_fallback: bool|string,
     *     local: bool,
     *     target_charset: string,
     * } $options
     * @return bool|string
     */
    public static function load_language($filename, $dirname = '', $options = [])
    {
        global $user, $language_files;

        // keep trace of plugins loaded files for switch_lang_to() function
        if (! empty($dirname) &&
            ! empty($filename) &&
            ! $options['return'] &&
            ! isset($language_files[$dirname][$filename])
        ) {
            $language_files[$dirname][$filename] = $options;
        }

        if (! $options['return']) {
            $filename .= '.php';
        }

        if (empty($dirname)) {
            $dirname = PHPWG_ROOT_PATH;
        }

        $dirname .= 'language/';

        $default_language = (defined('PHPWG_INSTALLED') and ! defined('UPGRADES_PATH')) ?
            functions_user::get_default_language() : PHPWG_DEFAULT_LANGUAGE;

        // construct list of potential languages
        $languages = [];

        if (! empty($options['language'])) { // explicit language
            $languages[] = $options['language'];
        }

        if (! empty($user['language'])) { // use language
            $languages[] = $user['language'];
        }

        $parent = self::get_parent_language();

        if ($parent != null) { // parent language
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

        if (! $options['no_fallback']) { // default language
            $languages[] = $default_language;
        }

        $languages = array_unique($languages);

        // find first existing
        $source_file = '';
        $selected_language = '';

        foreach ($languages as $language) {
            $f = $options['local'] ?
              $dirname . $language . '.' . $filename :
              $dirname . $language . '/' . $filename;

            if (file_exists($f)) {
                $selected_language = $language;
                $source_file = $f;
                break;
            }
        }

        if (! empty($source_file)) {
            if (! $options['return']) {
                // load forced fallback
                if (isset($options['force_fallback']) && $options['force_fallback'] != $selected_language) {
                    include(str_replace($selected_language, $options['force_fallback'], $source_file));
                }

                // load language content
                include($source_file);
                $load_lang = $lang;
                $load_lang_info = $lang_info;

                // access already existing values
                global $lang, $lang_info;

                if (! isset($lang)) {
                    $lang = [];
                }

                if (! isset($lang_info)) {
                    $lang_info = [];
                }

                // load parent language content directly in global
                if (! empty($load_lang_info['parent'])) {
                    $parent_language = $load_lang_info['parent'];
                } elseif (! empty($lang_info['parent'])) {
                    $parent_language = $lang_info['parent'];
                } else {
                    $parent_language = null;
                }

                if (! empty($parent_language) && $parent_language != $selected_language) {
                    include(str_replace($selected_language, $parent_language, $source_file));
                }

                // merge contents
                $lang = array_merge($lang, (array) $load_lang);
                $lang_info = array_merge($lang_info, (array) $load_lang_info);
                return true;
            }

            $content = file_get_contents($source_file);
            //Note: target charset is always utf-8 $content = convert_charset($content, 'utf-8', $target_charset);
            return $content;
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
    public static function convert_charset($str, $source_charset, $dest_charset)
    {
        if ($source_charset == $dest_charset) {
            return $str;
        }

        if ($source_charset == 'iso-8859-1' and
            $dest_charset == 'utf-8'
        ) {
            return utf8_encode($str);
        }

        if ($source_charset == 'utf-8' and
            $dest_charset == 'iso-8859-1'
        ) {
            return utf8_decode($str);
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
    public static function secure_directory($dir)
    {
        $file = $dir . '/index.htm';

        if (! file_exists($file)) {
            file_put_contents($file, 'Not allowed!');
        }
    }

    /**
     * returns a "secret key" that is to be sent back when a user posts a form
     *
     * @param int $valid_after_seconds - key validity start time from now
     * @param string $additional_data_to_hash
     * @return string
     */
    public static function get_ephemeral_key($valid_after_seconds, $additional_data_to_hash = '')
    {
        global $conf;
        $time = round(microtime(true), 1);
        return $time . ':' . $valid_after_seconds . ':'
          . hash_hmac(
              'md5',
              $time . substr($_SERVER['REMOTE_ADDR'], 0, 5) . $valid_after_seconds . $additional_data_to_hash,
              $conf['secret_key']
          );
    }

    /**
     * verify a key sent back with a form
     *
     * @param string $key
     * @param string $additional_data_to_hash
     * @return bool
     */
    public static function verify_ephemeral_key($key, $additional_data_to_hash = '')
    {
        global $conf;
        $time = microtime(true);
        $key = explode(':', $key);

        if (count($key) != 3 or
            $key[0] > $time - (float) $key[1] or // page must have been retrieved more than X sec ago
            $key[0] < $time - 3600 or // 60 minutes expiration
            hash_hmac(
                'md5',
                $key[0] . substr($_SERVER['REMOTE_ADDR'], 0, 5) . $key[1] . $additional_data_to_hash,
                $conf['secret_key']
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
     * @param int $nb_element
     * @param int $start
     * @param int $nb_element_page
     * @param bool $clean_url
     * @param string $param_name
     * @return array
     */
    public static function create_navigation_bar($url, $nb_element, $start, $nb_element_page, $clean_url = false, $param_name = 'start')
    {
        global $conf;

        $navbar = [];
        $pages_around = $conf['paginate_pages_around'];
        $start_str = $clean_url ? '/' . $param_name . '-' : (strpos($url, '?') === false ? '?' : '&amp;') . $param_name . '=';

        if (! isset($start) or
            ! is_numeric($start) or
            (is_numeric($start) and $start < 0)
        ) {
            $start = 0;
        }

        // navigation bar useful only if more than one page to display !
        if ($nb_element > $nb_element_page) {
            $url_start = $url . $start_str;

            $cur_page = $navbar['CURRENT_PAGE'] = $start / $nb_element_page + 1;
            $maximum = ceil($nb_element / $nb_element_page);

            $start = $nb_element_page * round($start / $nb_element_page);
            $previous = $start - $nb_element_page;
            $next = $start + $nb_element_page;
            $last = ($maximum - 1) * $nb_element_page;

            // link to first page and previous page?
            if ($cur_page != 1) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV'] = $previous > 0 ? $url_start . $previous : $url;
            }

            // link on next page and last page?
            if ($cur_page != $maximum) {
                $navbar['URL_NEXT'] = $url_start . ($next < $last ? $next : $last);
                $navbar['URL_LAST'] = $url_start . $last;
            }

            // pages to display
            $navbar['pages'] = [];
            $navbar['pages'][1] = $url;

            for ($i = max(floor($cur_page) - $pages_around, 2), $stop = min(ceil($cur_page) + $pages_around + 1, $maximum);
                $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $start_str . (($i - 1) * $nb_element_page);
            }

            $navbar['pages'][$maximum] = $url_start . $last;
            $navbar['NB_PAGE'] = $maximum;
        }

        return $navbar;
    }

    /**
     * return an array which will be sent to template to display recent icon
     *
     * @param string $date
     * @param bool $is_child_date
     * @return array
     */
    public static function get_icon($date, $is_child_date = false)
    {
        global $cache, $user;

        if (empty($date)) {
            return false;
        }

        if (! isset($cache['get_icon']['title'])) {
            $cache['get_icon']['title'] = self::l10n(
                'photos posted during the last %d days',
                $user['recent_period']
            );
        }

        $icon = [
            'TITLE' => $cache['get_icon']['title'],
            'IS_CHILD_DATE' => $is_child_date,
        ];

        if (isset($cache['get_icon'][$date])) {
            return $cache['get_icon'][$date] ? $icon : [];
        }

        if (! isset($cache['get_icon']['sql_recent_date'])) {
            // Use MySql date in order to standardize all recent "actions/queries"
            $cache['get_icon']['sql_recent_date'] = functions_mysqli::pwg_db_get_recent_period($user['recent_period']);
        }

        $cache['get_icon'][$date] = $date > $cache['get_icon']['sql_recent_date'];

        return $cache['get_icon'][$date] ? $icon : [];
    }

    /**
     * check token coming from form posted or get params to prevent csrf attacks.
     * if pwg_token is empty action doesn't require token
     * else pwg_token is compare to server token
     *
     * @throws SmartyException
     */
    public static function check_pwg_token()
    {
        if (! empty($_REQUEST['pwg_token'])) {
            if (self::get_pwg_token() != $_REQUEST['pwg_token']) {
                functions_html::access_denied();
            }
        } else {
            functions_html::bad_request('missing token');
        }
    }

    /**
     * get pwg_token used to prevent csrf attacks
     *
     * @return string
     */
    public static function get_pwg_token()
    {
        global $conf;

        return hash_hmac('md5', session_id(), $conf['secret_key']);
    }

    /**
     * breaks the script execution if the given value doesn't match the given
     * pattern. This should happen only during hacking attempts.
     *
     * @param string $param_name
     * @param array $param_array
     * @param bool $is_array
     * @param string $pattern
     * @param bool $mandatory
     */
    public static function check_input_parameter($param_name, $param_array, $is_array, $pattern, $mandatory = false)
    {
        $param_value = null;

        if (isset($param_array[$param_name])) {
            $param_value = $param_array[$param_name];
        }

        // it's ok if the input parameter is null
        if (empty($param_value)) {
            if ($mandatory) {
                functions_html::fatal_error('[Hacking attempt] the input parameter "' . $param_name . '" is not valid');
            }

            return true;
        }

        if ($is_array) {
            if (! is_array($param_value)) {
                functions_html::fatal_error('[Hacking attempt] the input parameter "' . $param_name . '" should be an array');
            }

            foreach ($param_value as $key => $item_to_check) {
                if (! preg_match(PATTERN_ID, $key) or
                    ! preg_match($pattern, $item_to_check)
                ) {
                    functions_html::fatal_error('[Hacking attempt] an item is not valid in input parameter "' . $param_name . '"');
                }
            }
        } else {
            if (! preg_match($pattern, $param_value)) {
                functions_html::fatal_error('[Hacking attempt] the input parameter "' . $param_name . '" is not valid');
            }
        }
    }

    /**
     * get localized privacy level values
     *
     * @return string[]
     */
    public static function get_privacy_level_options()
    {
        global $conf;

        $options = [];
        $label = '';

        foreach (array_reverse($conf['available_permission_levels']) as $level) {
            if ($level == 0) {
                $label = self::l10n('Everybody');
            } else {
                if (strlen($label)) {
                    $label .= ', ';
                }

                $label .= self::l10n(sprintf('Level %d', $level));
            }

            $options[$level] = $label;
        }

        return $options;
    }

    /**
     * return the branch from the version. For example version 11.1.2 is on branch 11
     *
     * @param string $version
     * @return string
     */
    public static function get_branch_from_version($version)
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
    public static function get_device()
    {
        $device = functions_session::pwg_get_session_var('device');

        if ($device === null) {
            $uagent_obj = new uagent_info();

            if ($uagent_obj->DetectSmartphone()) {
                $device = 'mobile';
            } elseif ($uagent_obj->DetectTierTablet()) {
                $device = 'tablet';
            } else {
                $device = 'desktop';
            }

            functions_session::pwg_set_session_var('device', $device);
        }

        return $device;
    }

    /**
     * return true if mobile theme should be loaded
     *
     * @return bool
     */
    public static function mobile_theme()
    {
        global $conf;

        if (empty($conf['mobile_theme'])) {
            return false;
        }

        if (isset($_GET['mobile'])) {
            $is_mobile_theme = functions_mysqli::get_boolean($_GET['mobile']);
            functions_session::pwg_set_session_var('mobile_theme', $is_mobile_theme);
        } else {
            $is_mobile_theme = functions_session::pwg_get_session_var('mobile_theme');
        }

        if ($is_mobile_theme === null) {
            $is_mobile_theme = (self::get_device() == 'mobile');
            functions_session::pwg_set_session_var('mobile_theme', $is_mobile_theme);
        }

        return $is_mobile_theme;
    }

    /**
     * check url format
     *
     * @param string $url
     * @return bool
     */
    public static function url_check_format($url)
    {
        if (strpos($url, '"') !== false) {
            return false;
        }

        if (strncmp($url, 'http://', 7) !== 0 and
            strncmp($url, 'https://', 8) !== 0
        ) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * check email format
     *
     * @param string $mail_address
     * @return bool
     */
    public static function email_check_format($mail_address)
    {
        return filter_var($mail_address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * returns the number of available comments for the connected user
     *
     * @return int
     */
    public static function get_nb_available_comments()
    {
        global $user;

        if (! isset($user['nb_available_comments'])) {
            $where = [];

            if (! functions_user::is_admin()) {
                $where[] = 'validated=\'true\'';
            }

            $where[] = functions_user::get_sql_condition_FandF(
                [
                    'forbidden_categories' => 'category_id',
                    'forbidden_images' => 'ic.image_id',
                ],
                '',
                true
            );

            $whereClause = implode(' AND ', $where);
            $query = <<<SQL
                SELECT COUNT(DISTINCT com.id)
                FROM image_category AS ic
                INNER JOIN comments AS com ON ic.image_id = com.image_id
                WHERE {$whereClause};
                SQL;
            list($user['nb_available_comments']) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            functions_mysqli::single_update(
                'user_cache',
                [
                    'nb_available_comments' => $user['nb_available_comments'],
                ],
                [
                    'user_id' => $user['id'],
                ]
            );
        }

        return $user['nb_available_comments'];
    }

    /**
     * Compare two versions with version_compare after having converted
     * single chars to their decimal values.
     * Needed because version_compare does not understand versions like '2.5.c'.
     *
     * @param string $a
     * @param string $b
     * @param string $op
     */
    public static function safe_version_compare($a, $b, $op = null)
    {
        $replace_chars = function ($m) { return ord(strtolower($m[1])); };

        // add dot before groups of letters (version_compare does the same thing)
        $a = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $a);
        $b = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $b);

        // apply ord() to any single letter
        $a = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, $a);
        $b = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, $b);

        if (empty($op)) {
            return version_compare($a, $b);
        }

        return version_compare($a, $b, $op);
    }

    /**
     * Checks if the lounge needs to be emptied automatically.
     */
    public static function check_lounge()
    {
        global $conf;

        if (! isset($conf['lounge_active']) or
            ! $conf['lounge_active']
        ) {
            return;
        }

        if (isset($_REQUEST['method']) and
            in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'])
        ) {
            return;
        }

        // is the oldest photo in the lounge older than lounge maximum waiting time?
        $query = <<<SQL
            SELECT image_id, date_available, NOW() AS dbnow
            FROM lounge
            JOIN images ON image_id = id
            ORDER BY image_id ASC
            LIMIT 1;
            SQL;
        $voyagers = functions_mysqli::query2array($query);

        if (count($voyagers)) {
            $voyager = $voyagers[0];
            $age = strtotime($voyager['dbnow']) - strtotime($voyager['date_available']);

            if ($age > $conf['lounge_max_duration']) {
                functions_admin::empty_lounge();
            }
        }
    }

    public static function guess_mime_type($ext)
    {
        switch (strtolower($ext)) {
            case 'jpe': case 'jpeg':
            case 'jpg': $ctype = 'image/jpeg';
                break;

            case 'png': $ctype = 'image/png';
                break;

            case 'gif': $ctype = 'image/gif';
                break;

            case 'webp': $ctype = 'image/webp';
                break;

            case 'tiff':
            case 'tif': $ctype = 'image/tiff';
                break;

            case 'txt': $ctype = 'text/plain';
                break;

            case 'html':
            case 'htm': $ctype = 'text/html';
                break;

            case 'xml': $ctype = 'text/xml';
                break;

            case 'pdf': $ctype = 'application/pdf';
                break;

            case 'zip': $ctype = 'application/zip';
                break;

            case 'ogg': $ctype = 'application/ogg';
                break;

            default: $ctype = 'application/octet-stream';
        }

        return $ctype;
    }

    public static function do_error($code, $str)
    {
        functions_html::set_status_header($code);
        echo $str;
        exit();
    }

    /**
     * creates a Unix timestamp (number of seconds since 1970-01-01 00:00:00
     * GMT) from a MySQL datetime format (2005-07-14 23:01:37)
     *
     * @param string $datetime mysql datetime format
     * @return int timestamp
     */
    public static function datetime_to_ts($datetime)
    {
        return strtotime($datetime);
    }

    /**
     * creates an ISO 8601 format date (2003-01-20T18:05:41+04:00) from Unix
     * timestamp (number of seconds since 1970-01-01 00:00:00 GMT)
     *
     * function copied from Dotclear project http://dotclear.net
     *
     * @param int $ts timestamp
     * @return string ISO 8601 date format
     */
    public static function ts_to_iso8601($ts)
    {
        $tz = date('O', $ts);
        $tz = substr($tz, 0, -2) . ':' . substr($tz, -2);
        return date('Y-m-d\\TH:i:s', $ts) . $tz;
    }

    public static function ierror($msg, $code)
    {
        global $logger;

        if ($code == 301 ||
            $code == 302
        ) {
            if (ob_get_length() !== false) {
                ob_clean();
            }

            // default url is on html format
            $url = html_entity_decode($msg);
            $logger->debug($code . ' ' . $url, [
                'url' => $_SERVER['REQUEST_URI'],
            ]);
            header('Request-URI: ' . $url);
            header('Content-Location: ' . $url);
            header('Location: ' . $url);
            exit;
        }

        if ($code >= 400) {
            $protocol = $_SERVER['SERVER_PROTOCOL'];

            if ($protocol != 'HTTP/1.1' &&
                $protocol != 'HTTP/1.0'
            ) {
                $protocol = 'HTTP/1.0';
            }

            header("{$protocol} {$code} {$msg}", true, $code);
        }

        //todo improve
        echo $msg;
        $logger->error($code . ' ' . $msg, [
            'url' => $_SERVER['REQUEST_URI'],
        ]);
        exit;
    }

    public static function time_step(&$step)
    {
        $tmp = $step;
        $step = microtime(true);
        return intval(1000 * ($step - $tmp));
    }

    public static function url_to_size($s)
    {
        $pos = strpos($s, 'x');

        if ($pos === false) {
            return [(int) $s, (int) $s];
        }

        return [(int) substr($s, 0, $pos), (int) substr($s, $pos + 1)];
    }

    public static function parse_custom_params($tokens)
    {
        if (count($tokens) < 1) {
            self::ierror('Empty array while parsing Sizing', 400);
        }

        $crop = 0;
        $min_size = null;

        $token = array_shift($tokens);

        if ($token[0] == 's') {
            $size = self::url_to_size(substr($token, 1));
        } elseif ($token[0] == 'e') {
            $crop = 1;
            $size = $min_size = self::url_to_size(substr($token, 1));
        } else {
            $size = self::url_to_size($token);

            if (count($tokens) < 2) {
                self::ierror('Sizing arr', 400);
            }

            $token = array_shift($tokens);
            $crop = derivative_params::char_to_fraction($token);

            $token = array_shift($tokens);
            $min_size = self::url_to_size($token);
        }

        return new DerivativeParams(new SizingParams($size, $crop, $min_size));
    }

    public static function parse_request()
    {
        global $conf, $page;

        if ($conf['question_mark_in_urls'] == false and
            isset($_SERVER['PATH_INFO']) and
            ! empty($_SERVER['PATH_INFO'])
        ) {
            $req = $_SERVER['PATH_INFO'];
            $req = str_replace('//', '/', $req);
            $path_count = count(explode('/', $req));
            $page['root_path'] = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
        } else {
            $req = $_SERVER['QUERY_STRING'];
            $pos = strpos($req, '&');

            if ($pos) {
                $req = substr($req, 0, $pos);
            }

            $req = rawurldecode($req);
            /*foreach (array_keys($_GET) as $keynum => $key)
            {
              $req = $key;
              break;
            }*/
            $page['root_path'] = PHPWG_ROOT_PATH;
        }

        $req = ltrim($req, '/');

        foreach (preg_split('#/+#', $req) as $token) {
            if (! preg_match($conf['sync_chars_regex'], $token)) {
                self::ierror('Invalid chars in request', 400);
            }
        }

        $page['derivative_path'] = PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $req;

        $pos = strrpos($req, '.');

        if ($pos === false) {
            self::ierror('Missing .', 400);
        }

        $ext = substr($req, $pos);
        $page['derivative_ext'] = $ext;
        $req = substr($req, 0, $pos);

        $pos = strrpos($req, '-');

        if ($pos === false) {
            self::ierror('Missing -', 400);
        }

        $deriv = substr($req, $pos + 1);
        $req = substr($req, 0, $pos);

        $deriv = explode('_', $deriv);

        foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
            if (derivative_params::derivative_to_url($type) == $deriv[0]) {
                $page['derivative_type'] = $type;
                $page['derivative_params'] = $params;
                break;
            }
        }

        if (! isset($page['derivative_type'])) {
            if (derivative_params::derivative_to_url(derivative_std_params::IMG_CUSTOM) == $deriv[0]) {
                $page['derivative_type'] = derivative_std_params::IMG_CUSTOM;
            } else {
                self::ierror('Unknown parsing type', 400);
            }
        }

        array_shift($deriv);

        if ($page['derivative_type'] == derivative_std_params::IMG_CUSTOM) {
            $params = $page['derivative_params'] = self::parse_custom_params($deriv);
            ImageStdParams::apply_global($params);

            if ($params->sizing->ideal_size[0] < 20 or
                $params->sizing->ideal_size[1] < 20
            ) {
                self::ierror('Invalid size', 400);
            }

            if ($params->sizing->max_crop < 0 or
                $params->sizing->max_crop > 1
            ) {
                self::ierror('Invalid crop', 400);
            }

            $greatest = ImageStdParams::get_by_type(derivative_std_params::IMG_XXLARGE);

            $key = [];
            $params->add_url_tokens($key);
            $key = implode('_', $key);

            if (! isset(ImageStdParams::$custom[$key])) {
                self::ierror('Size not allowed', 403);
            }
        }

        if (is_file(PHPWG_ROOT_PATH . $req . $ext)) {
            $req = './' . $req; // will be used to match #iamges.path
        } elseif (is_file(PHPWG_ROOT_PATH . '../' . $req . $ext)) {
            $req = '../' . $req;
        }

        $page['src_location'] = $req . $ext;
        $page['src_path'] = PHPWG_ROOT_PATH . $page['src_location'];
        $page['src_url'] = $page['root_path'] . $page['src_location'];
    }

    public static function try_switch_source(DerivativeParams $params, $original_mtime)
    {
        global $page;

        if (! isset($page['original_size'])) {
            return false;
        }

        $original_size = $page['original_size'];

        if ($page['rotation_angle'] == 90 ||
            $page['rotation_angle'] == 270
        ) {
            $tmp = $original_size[0];
            $original_size[0] = $original_size[1];
            $original_size[1] = $tmp;
        }

        $dsize = $params->compute_final_size($original_size);

        $use_watermark = $params->use_watermark;

        if ($use_watermark) {
            $use_watermark = $params->will_watermark($dsize);
        }

        $candidates = [];

        foreach (ImageStdParams::get_defined_type_map() as $candidate) {
            if ($candidate->type == $params->type) {
                continue;
            }

            if ($candidate->use_watermark != $use_watermark) {
                continue;
            }

            if ($candidate->max_width() < $params->max_width() ||
                $candidate->max_height() < $params->max_height()
            ) {
                continue;
            }

            $candidate_size = $candidate->compute_final_size($original_size);

            if ($dsize != $params->compute_final_size($candidate_size)) {
                continue;
            }

            if ($params->sizing->max_crop == 0) {
                if ($candidate->sizing->max_crop != 0) {
                    continue;
                }
            } else {
                if ($use_watermark &&
                    $candidate->use_watermark
                ) {
                    continue;
                    //a square that requires watermark should not be generated from a larger derivative with watermark, because if the watermark is not centered on the large image, it will be cropped.
                }

                if ($candidate->sizing->max_crop != 0) {
                    continue;
                    // this could be optimized
                }

                if ($candidate_size[0] < $params->sizing->min_size[0] ||
                    $candidate_size[1] < $params->sizing->min_size[1]
                ) {
                    continue;
                }
            }

            $candidates[] = $candidate;
        }

        foreach (array_reverse($candidates) as $candidate) {
            $candidate_path = $page['derivative_path'];
            $candidate_path = str_replace('-' . derivative_params::derivative_to_url($params->type), '-' . derivative_params::derivative_to_url($candidate->type), $candidate_path);
            $candidate_mtime = filemtime($candidate_path);

            if ($candidate_mtime === false ||
                $candidate_mtime < $original_mtime ||
                $candidate_mtime < $candidate->last_mod_time
            ) {
                continue;
            }

            $params->use_watermark = false;
            $params->sharpen = min(1, $params->sharpen);
            $page['src_path'] = $candidate_path;
            $page['src_url'] = $page['root_path'] . substr($candidate_path, strlen(PHPWG_ROOT_PATH));
            $page['rotation_angle'] = 0;
            return true;
        }

        return false;
    }

    public static function send_derivative($expires)
    {
        global $page;

        if (isset($_GET['ajaxload']) and
            $_GET['ajaxload'] == 'true'
        ) {
            echo json_encode([
                'url' => functions_url::embellish_url(functions_url::get_absolute_root_url() . $page['derivative_path']),
            ]);
            return;
        }

        $fp = fopen($page['derivative_path'], 'rb');

        $fstat = fstat($fp);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fstat['mtime']) . ' GMT');

        if ($expires !== false) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
        }

        header('Connection: close');

        $ctype = 'application/octet-stream';

        switch (strtolower($page['derivative_ext'])) {
            case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg';
                break;

            case '.png': $ctype = 'image/png';
                break;

            case '.gif': $ctype = 'image/gif';
                break;

            case '.webp': $ctype = 'image/webp';
                break;
        }

        header("Content-Type: {$ctype}");

        fpassthru($fp);
        fclose($fp);
    }

    /**
     * search an available feed_id
     *
     * @return string feed identifier
     * @throws RandomException
     */
    public static function find_available_feed_id()
    {
        while (true) {
            $key = functions_session::generate_key(50);
            $query = <<<SQL
                SELECT COUNT(*)
                FROM user_feed
                WHERE id = '{$key}';
                SQL;
            list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($count == 0) {
                return $key;
            }
        }
    }

    /**
     * checks the validity of input parameters, fills $page['errors'] and
     * $page['infos'] and send an email with confirmation link
     *
     * @return bool (true if email was sent, false otherwise)
     * @throws RandomException
     */
    public static function process_password_request()
    {
        global $page, $conf;

        if (empty($_POST['username_or_email'])) {
            $page['errors'][] = self::l10n('Invalid username or email');
            return false;
        }

        $user_id = functions_user::get_userid_by_email($_POST['username_or_email']);

        if (! is_numeric($user_id)) {
            $user_id = functions_user::get_userid($_POST['username_or_email']);
        }

        if (! is_numeric($user_id)) {
            $page['errors'][] = self::l10n('Invalid username or email');
            return false;
        }

        $userdata = functions_user::getuserdata($user_id, false);

        // password request is not possible for guest/generic users
        $status = $userdata['status'];

        if (functions_user::is_a_guest($status) or
            functions_user::is_generic($status)
        ) {
            $page['errors'][] = self::l10n('Password reset is not allowed for this user');
            return false;
        }

        if (empty($userdata['email'])) {
            $page['errors'][] = self::l10n(
                'User "%s" has no email address, password reset is not possible',
                $userdata['username']
            );
            return false;
        }

        $activation_key = functions_session::generate_key(20);

        list($expire) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT ADDDATE(NOW(), INTERVAL 1 HOUR);'));

        functions_mysqli::single_update(
            'user_infos',
            [
                'activation_key' => functions_user::pwg_password_hash($activation_key),
                'activation_key_expire' => $expire,
            ],
            [
                'user_id' => $user_id,
            ]
        );

        $userdata['activation_key'] = $activation_key;

        functions_url::set_make_full_url();

        $message = self::l10n('Someone requested that the password be reset for the following user account:') . "\r\n\r\n";
        $message .= self::l10n(
            'Username "%s" on gallery %s',
            $userdata['username'],
            functions_url::get_gallery_home_url()
        );
        $message .= "\r\n\r\n";
        $message .= self::l10n('To reset your password, visit the following address:') . "\r\n";
        $message .= functions_url::get_root_url() . 'password.php?key=' . $activation_key . '-' . urlencode($userdata['email']);
        $message .= "\r\n\r\n";
        $message .= self::l10n('If this was a mistake, just ignore this email and nothing will happen.') . "\r\n";

        functions_url::unset_make_full_url();

        $message = functions_plugins::trigger_change('render_lost_password_mail_content', $message);

        $email_params = [
            'subject' => '[' . $conf['gallery_title'] . '] ' . self::l10n('Password Reset'),
            'content' => $message,
            'email_format' => 'text/plain',
        ];

        if (functions_mail::pwg_mail($userdata['email'], $email_params)) {
            $page['infos'][] = self::l10n('Check your email for the confirmation link');
            return true;
        }

        $page['errors'][] = self::l10n('Error sending email');
        return false;
    }

    /**
     *  checks the activation key: does it match the expected pattern? is it
     *  linked to a user? is this user allowed to reset his password?
     *
     * @return mixed (user_id if OK, false otherwise)
     */
    public static function check_password_reset_key($reset_key)
    {
        global $page, $conf;

        list($key, $email) = explode('-', $reset_key, 2);

        if (! preg_match('/^[a-z0-9]{20}$/i', $key)) {
            $page['errors'][] = self::l10n('Invalid key');
            return false;
        }

        $user_ids = [];

        $escaped_email = functions_mysqli::pwg_db_real_escape_string($email);
        $query = <<<SQL
            SELECT {$conf['user_fields']['id']} AS id
            FROM users
            WHERE {$conf['user_fields']['email']} = '{$escaped_email}';
            SQL;
        $user_ids = functions_mysqli::query2array($query, null, 'id');

        if (count($user_ids) == 0) {
            $page['errors'][] = self::l10n('Invalid username or email');
            return false;
        }

        $user_id = null;

        $imploded_user_ids = implode(', ', $user_ids);
        $query = <<<SQL
            SELECT user_id, status, activation_key, activation_key_expire, NOW() AS dbnow
            FROM user_infos
            WHERE user_id IN ({$imploded_user_ids});
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            if (functions_user::pwg_password_verify($key, $row['activation_key'])) {
                if (strtotime($row['dbnow']) > strtotime($row['activation_key_expire'])) {
                    // key has expired
                    $page['errors'][] = self::l10n('Invalid key');
                    return false;
                }

                if (functions_user::is_a_guest($row['status']) or
                    functions_user::is_generic($row['status'])
                ) {
                    $page['errors'][] = self::l10n('Password reset is not allowed for this user');
                    return false;
                }

                $user_id = $row['user_id'];
            }
        }

        if (empty($user_id)) {
            $page['errors'][] = self::l10n('Invalid key');
            return false;
        }

        return $user_id;
    }

    /**
     * checks the passwords, checks that user is allowed to reset his password,
     * update password, fills $page['errors'] and $page['infos'].
     *
     * @return bool (true if password was reset, false otherwise)
     */
    public static function reset_password()
    {
        global $page, $conf;

        if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
            $page['errors'][] = self::l10n('The passwords do not match');
            return false;
        }

        if (! isset($_GET['key'])) {
            $page['errors'][] = self::l10n('Invalid key');
        }

        $user_id = self::check_password_reset_key($_GET['key']);

        if (! is_numeric($user_id)) {
            return false;
        }

        functions_mysqli::single_update(
            'users',
            [
                $conf['user_fields']['password'] => $conf['password_hash']($_POST['use_new_pwd']),
            ],
            [
                $conf['user_fields']['id'] => $user_id,
            ]
        );

        functions_user::deactivate_password_reset_key($user_id);
        functions_user::deactivate_user_auth_keys($user_id);

        $page['infos'][] = self::l10n('Your password has been reset');
        $page['infos'][] = '<a href="' . functions_url::get_root_url() . 'identification.php">' . self::l10n('Login') . '</a>';

        return true;
    }

    /**
     * pwg_nl2br is useful for PHP 5.2 which doesn't accept more than 1
     * parameter on nl2br() (and anyway the second parameter of nl2br does not
     * match what Piwigo gives.
     */
    public static function pwg_nl2br($string)
    {
        return nl2br($string);
    }

    // this is the default handler that generates the display for the element
    public static function default_picture_content($content, $element_info)
    {
        global $conf;

        if (! empty($content)) { // someone hooked us - so we skip;
            return $content;
        }

        if (isset($_COOKIE['picture_deriv'])) {
            if (array_key_exists($_COOKIE['picture_deriv'], ImageStdParams::get_defined_type_map())) {
                functions_session::pwg_set_session_var('picture_deriv', $_COOKIE['picture_deriv']);
            }

            setcookie('picture_deriv', false, 0, functions_cookie::cookie_path());
        }

        $deriv_type = functions_session::pwg_get_session_var('picture_deriv', $conf['derivative_default_size']);
        $selected_derivative = $element_info['derivatives'][$deriv_type];

        $unique_derivatives = [];
        $show_original = isset($element_info['element_url']);
        $added = [];

        foreach ($element_info['derivatives'] as $type => $derivative) {
            if ($type == derivative_std_params::IMG_SQUARE ||
                $type == derivative_std_params::IMG_THUMB
            ) {
                continue;
            }

            if (! array_key_exists($type, ImageStdParams::get_defined_type_map())) {
                continue;
            }

            $url = $derivative->get_url();

            if (isset($added[$url])) {
                continue;
            }

            $added[$url] = 1;
            $show_original &= ! ($derivative->same_as_source());

            // in case we do not display the sizes icon, we only add the selected size to unique_derivatives
            if ($conf['picture_sizes_icon'] or
                $type == $deriv_type
            ) {
                $unique_derivatives[$type] = $derivative;
            }
        }

        global $page, $template;

        if ($show_original) {
            $template->assign('U_ORIGINAL', $element_info['element_url']);
        }

        $template->append('current', [
            'selected_derivative' => $selected_derivative,
            'unique_derivatives' => $unique_derivatives,
        ], true);

        $template->set_filenames(
            [
                'default_content' => 'picture_content.tpl',
            ]
        );

        $template->assign(
            [
                'ALT_IMG' => $element_info['file'],
                'COOKIE_PATH' => functions_cookie::cookie_path(),
            ]
        );

        return $template->parse('default_content', true);
    }

    /**
     * list all tables in an array
     *
     * @return array
     */
    public static function get_tables()
    {
        $tables = [];

        $query = <<<SQL
            SHOW TABLES;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_row($result)) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * list all columns of each given table
     *
     * @return array of array
     */
    public static function get_columns_of($tables)
    {
        $columns_of = [];

        foreach ($tables as $table) {
            $query = <<<SQL
                DESC {$table};
                SQL;
            $result = functions_mysqli::pwg_query($query);

            $columns_of[$table] = [];

            while ($row = functions_mysqli::pwg_db_fetch_row($result)) {
                $columns_of[$table][] = $row[0];
            }
        }

        return $columns_of;
    }

    public static function print_time($message)
    {
        global $last_time;

        $new_time = self::get_moment();
        echo '<pre>[' . self::get_elapsed_time($last_time, $new_time) . ']';
        echo ' ' . $message;
        echo '</pre>';
        flush();
        $last_time = $new_time;
    }

    public static function save_profile_from_post($userdata, &$errors)
    {
        global $conf, $page;
        $errors = [];

        if (! isset($_POST['validate'])) {
            return false;
        }

        $special_user = in_array($userdata['id'], [$conf['guest_id'], $conf['default_user_id']]);

        if ($special_user) {
            unset(
                $_POST['username'],
                $_POST['mail_address'],
                $_POST['password'],
                $_POST['use_new_pwd'],
                $_POST['passwordConf'],
                $_POST['theme'],
                $_POST['language']
            );
            $_POST['theme'] = functions_user::get_default_theme();
            $_POST['language'] = functions_user::get_default_language();
        }

        if (! defined('IN_ADMIN')) {
            unset($_POST['username']);
        }

        if ($conf['allow_user_customization'] or
            defined('IN_ADMIN')
        ) {
            $int_pattern = '/^\d+$/';

            if (empty($_POST['nb_image_page']) or
                ! preg_match($int_pattern, $_POST['nb_image_page'])
            ) {
                $errors[] = self::l10n('The number of photos per page must be a not null scalar');
            }

            // periods must be integer values, they represents number of days
            if (! preg_match($int_pattern, $_POST['recent_period']) or
                $_POST['recent_period'] < 0
            ) {
                $errors[] = self::l10n('Recent period must be a positive integer value');
            }

            if (! in_array($_POST['language'], array_keys(self::get_languages()))) {
                die('Hacking attempt, incorrect language value');
            }

            if (! in_array($_POST['theme'], array_keys(self::get_pwg_themes()))) {
                die('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($_POST['mail_address'])) {
            // if $_POST and $userdata have are same email
            // validate_mail_address allows, however, to check email
            $mail_error = functions_user::validate_mail_address($userdata['id'], $_POST['mail_address']);

            if (! empty($mail_error)) {
                $errors[] = $mail_error;
            }
        }

        if (! empty($_POST['use_new_pwd'])) {
            // password must be the same as its confirmation
            if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
                $errors[] = self::l10n('The passwords do not match');
            }

            if (! defined('IN_ADMIN')) { // changing password requires old password
                $query = <<<SQL
                    SELECT {$conf['user_fields']['password']} AS password
                    FROM users
                    WHERE {$conf['user_fields']['id']} = '{$userdata['id']}';
                    SQL;
                list($current_password) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

                if (! $conf['password_verify']($_POST['password'], $current_password)) {
                    $errors[] = self::l10n('Current password is wrong');
                }
            }
        }

        if (count($errors) == 0) {
            // mass_updates function

            $activity_details_tables = [];

            if (isset($_POST['mail_address'])) {
                // update common user information
                $fields = [$conf['user_fields']['email']];

                $data = [];
                $data[$conf['user_fields']['id']] = $userdata['id'];
                $data[$conf['user_fields']['email']] = $_POST['mail_address'];

                // password is updated only if filled
                if (! empty($_POST['use_new_pwd'])) {
                    $fields[] = $conf['user_fields']['password'];
                    // password is hashed with function $conf['password_hash']
                    $data[$conf['user_fields']['password']] = $conf['password_hash']($_POST['use_new_pwd']);

                    functions_user::deactivate_user_auth_keys($userdata['id']);
                }

                // username is updated only if allowed
                if (! empty($_POST['username'])) {
                    if ($_POST['username'] != $userdata['username'] and
                        functions_user::get_userid($_POST['username'])
                    ) {
                        $page['errors'][] = self::l10n('this login is already used');
                        unset($_POST['redirect']);
                    } else {
                        $fields[] = $conf['user_fields']['username'];
                        $data[$conf['user_fields']['username']] = $_POST['username'];

                        // send email to the user
                        if ($_POST['username'] != $userdata['username']) {
                            include_once(PHPWG_ROOT_PATH . 'inc/functions_mail.php');
                            functions_mail::switch_lang_to($userdata['language']);

                            $keyargs_content = [
                                self::get_l10n_args('Hello', ''),
                                self::get_l10n_args('Your username has been successfully changed to : %s', $_POST['username']),
                            ];

                            functions_mail::pwg_mail(
                                $_POST['mail_address'],
                                [
                                    'subject' => '[' . $conf['gallery_title'] . '] ' . self::l10n('Username modification'),
                                    'content' => self::l10n_args($keyargs_content),
                                    'content_format' => 'text/plain',
                                ]
                            );

                            functions_mail::switch_lang_back();
                        }
                    }
                }

                functions_mysqli::mass_updates(
                    'users',
                    [
                        'primary' => [$conf['user_fields']['id']],
                        'update' => $fields,
                    ],
                    [$data]
                );

                if ($_POST['mail_address'] != $userdata['email']) {
                    functions_user::deactivate_password_reset_key($userdata['id']);
                }

                $activity_details_tables[] = 'users';
            }

            if ($conf['allow_user_customization'] or
                defined('IN_ADMIN')
            ) {
                // update user "additional" information (specific to Piwigo)
                $fields = [
                    'nb_image_page', 'language',
                    'expand', 'show_nb_hits', 'recent_period', 'theme',
                ];

                if ($conf['activate_comments']) {
                    $fields[] = 'show_nb_comments';
                }

                $data = [];
                $data['user_id'] = $userdata['id'];

                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        $data[$field] = $_POST[$field];
                    }
                }

                functions_mysqli::mass_updates(
                    'user_infos',
                    [
                        'primary' => ['user_id'],
                        'update' => $fields,
                    ],
                    [$data]
                );

                $activity_details_tables[] = 'user_infos';
            }

            functions_plugins::trigger_notify('save_profile_from_post', $userdata['id']);
            self::pwg_activity('user', $userdata['id'], 'edit', [
                'function' => __FUNCTION__,
                'tables' => implode(',', $activity_details_tables),
            ]);

            if (! empty($_POST['redirect'])) {
                self::redirect($_POST['redirect']);
            }
        }

        return true;
    }

    /**
     * Assign template variables, from arguments
     * Used to build profile edition pages
     *
     * @param string $url_action
     * @param string $url_redirect
     * @param array $userdata
     */
    public static function load_profile_in_template($url_action, $url_redirect, $userdata, $template_prefix = null)
    {
        global $template, $conf;

        $template->assign(
            'radio_options',
            [
                'true' => self::l10n('Yes'),
                'false' => self::l10n('No'),
            ]
        );

        $template->assign(
            [
                $template_prefix . 'USERNAME' => stripslashes($userdata['username']),
                $template_prefix . 'EMAIL' => $userdata['email'],
                $template_prefix . 'ALLOW_USER_CUSTOMIZATION' => $conf['allow_user_customization'],
                $template_prefix . 'ACTIVATE_COMMENTS' => $conf['activate_comments'],
                $template_prefix . 'NB_IMAGE_PAGE' => $userdata['nb_image_page'],
                $template_prefix . 'RECENT_PERIOD' => $userdata['recent_period'],
                $template_prefix . 'EXPAND' => $userdata['expand'] ? 'true' : 'false',
                $template_prefix . 'NB_COMMENTS' => $userdata['show_nb_comments'] ? 'true' : 'false',
                $template_prefix . 'NB_HITS' => $userdata['show_nb_hits'] ? 'true' : 'false',
                $template_prefix . 'REDIRECT' => $url_redirect,
                $template_prefix . 'F_ACTION' => $url_action,
            ]
        );

        $template->assign('template_selection', $userdata['theme']);
        $template->assign('template_options', self::get_pwg_themes());

        foreach (self::get_languages() as $language_code => $language_name) {
            if (isset($_POST['submit']) or
                $userdata['language'] == $language_code
            ) {
                $template->assign('language_selection', $language_code);
            }

            $language_options[$language_code] = $language_name;
        }

        $template->assign('language_options', $language_options);

        $special_user = in_array($userdata['id'], [$conf['guest_id'], $conf['default_user_id']]);
        $template->assign('SPECIAL_USER', $special_user);
        $template->assign('IN_ADMIN', defined('IN_ADMIN'));

        // allow plugins to add their own form data to content
        functions_plugins::trigger_notify('load_profile_in_template', $userdata);

        $template->assign('PWG_TOKEN', self::get_pwg_token());
    }
}
