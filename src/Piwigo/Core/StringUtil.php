<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Url\UrlService;

final class StringUtil
{
    public function microSeconds(): string
    {
        $t1 = explode(' ', microtime());
        $t2 = explode('.', $t1[0]);
        return $t1[1] . substr($t2[1], 0, 6);
    }

    public static function getMoment(): float
    {
        return microtime(true);
    }

    public static function getElapsedTime(float $start, float $end): string
    {
        return number_format($end - $start, 3, '.', ' ') . ' s';
    }

    /** @param array<string,mixed> $source */
    public static function inputInt(string $key, ?int $default = null, array $source = []): ?int
    {
        $src = $source ?: ($_POST + $_GET);
        return isset($src[$key]) ? (is_numeric($src[$key]) ? (int) $src[$key] : 0) : $default;
    }

    /** @param array<string,mixed> $source */
    public static function inputString(string $key, ?string $default = null, array $source = []): ?string
    {
        $src = $source ?: ($_POST + $_GET);
        return isset($src[$key]) ? trim(is_scalar($src[$key]) ? (string) $src[$key] : '') : $default;
    }

    /** @param array<string,mixed> $source */
    public static function inputBool(string $key, ?bool $default = null, array $source = []): ?bool
    {
        $src = $source ?: ($_POST + $_GET);
        return isset($src[$key]) ? (bool) $src[$key] : $default;
    }

    public function getExtension(string $filename): string
    {
        $ext = strrchr($filename, '.');
        return $ext !== false ? substr($ext, 1) : '';
    }

    public function getFilenameWoExtension(string $filename): string
    {
        $pos = strrpos($filename, '.');
        return ($pos === false) ? $filename : substr($filename, 0, $pos);
    }

    public function getNameFromFile(string $filename): string
    {
        return str_replace('_', ' ', $this->getFilenameWoExtension($filename));
    }

    /** @return int 0 if ASCII, 1 if UTF-8, -1 otherwise */
    public function qualifyUtf8(string $str): int
    {
        $ret = 0;
        for ($i = 0; $i < strlen($str); $i++) {
            if (ord($str[$i]) < 0x80) {
                continue;
            }
            $ret = 1;
            $n   = 0;
            if ((ord($str[$i]) & 0xE0) === 0xC0) {
                $n = 1;
            } elseif ((ord($str[$i]) & 0xF0) === 0xE0) {
                $n = 2;
            } elseif ((ord($str[$i]) & 0xF8) === 0xF0) {
                $n = 3;
            } elseif ((ord($str[$i]) & 0xFC) === 0xF8) {
                $n = 4;
            } elseif ((ord($str[$i]) & 0xFE) === 0xFC) {
                $n = 5;
            } else {
                return -1;
            }
            for ($j = 0; $j < $n; $j++) {
                if ((++$i === strlen($str)) || ((ord($str[$i]) & 0xC0) !== 0x80)) {
                    return -1;
                }
            }
        }
        return $ret;
    }

    public function removeAccents(string $string): string
    {
        $utf = $this->qualifyUtf8($string);
        if ($utf === 0) {
            return $string;
        }

        if ($utf > 0) {
            $chars = [
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
                "\xc8\x98" => 'S', "\xc8\x99" => 's',
                "\xc8\x9a" => 'T', "\xc8\x9b" => 't',
                "\xe2\x82\xac" => 'E',
                "\xc2\xa3" => '',
            ];
            $string = strtr($string, $chars);
        } else {
            $in  = chr(128) . chr(131) . chr(138) . chr(142) . chr(154) . chr(158)
                . chr(159) . chr(162) . chr(165) . chr(181) . chr(192) . chr(193) . chr(194)
                . chr(195) . chr(196) . chr(197) . chr(199) . chr(200) . chr(201) . chr(202)
                . chr(203) . chr(204) . chr(205) . chr(206) . chr(207) . chr(209) . chr(210)
                . chr(211) . chr(212) . chr(213) . chr(214) . chr(216) . chr(217) . chr(218)
                . chr(219) . chr(220) . chr(221) . chr(224) . chr(225) . chr(226) . chr(227)
                . chr(228) . chr(229) . chr(231) . chr(232) . chr(233) . chr(234) . chr(235)
                . chr(236) . chr(237) . chr(238) . chr(239) . chr(241) . chr(242) . chr(243)
                . chr(244) . chr(245) . chr(246) . chr(248) . chr(249) . chr(250) . chr(251)
                . chr(252) . chr(253) . chr(255);
            $out    = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';
            $string = strtr($string, $in, $out);

            $doubleIn  = [chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254)];
            $doubleOut = ['OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th'];
            $string    = str_replace($doubleIn, $doubleOut, $string);
        }

        return $string;
    }

    public function pwgTransliterate(string $term): string
    {
        return $this->removeAccents(mb_strtolower($term, 'utf-8'));
    }

    public function str2url(string $str): string
    {
        $str  = $safe = $this->pwgTransliterate($str);
        $str  = preg_replace('/[^\x80-\xffa-z0-9_\s\'\:\/\[\],-]/', '', $str);
        $str  = preg_replace('/[\s\'\:\/\[\],-]+/', ' ', trim((string) $str));
        $res  = str_replace(' ', '_', $str ?? '');
        if (empty($res)) {
            $res = str_replace(' ', '_', $safe);
        }
        return $res;
    }

    public function convertCharset(string $str, string $sourceCharset, string $destCharset): string
    {
        if ($sourceCharset === $destCharset) {
            return $str;
        }
        if ($sourceCharset === 'iso-8859-1' && $destCharset === 'utf-8') {
            return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }
        if ($sourceCharset === 'utf-8' && $destCharset === 'iso-8859-1') {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }
        if (function_exists('iconv')) {
            $result = iconv($sourceCharset, $destCharset . '//TRANSLIT', $str);
            return $result !== false ? $result : $str;
        }
        $result = mb_convert_encoding($str, $destCharset, $sourceCharset);
        return $result !== false ? $result : $str;
    }

    public function getPwgCharset(): string
    {
        return 'utf-8';
    }

    /**
     * @param string[] $array
     * @return string[]
     */
    public function prependAppendArrayItems(array $array, string $prependStr, string $appendStr): array
    {
        array_walk($array, static function (mixed &$value, int|string $key) use ($prependStr, $appendStr): void {
            $value = $prependStr . (string) $value . $appendStr;
        });
        return $array;
    }

    /**
     * @param array<mixed>|string $value
     * @return array<mixed>
     */
    public static function safeUnserialize(array|string $value): array
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
     * @param array<mixed>|string $value
     * @return array<mixed>
     */
    public function safeJsonDecode(array|string $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $value;
    }

    /**
     * @param array<string,mixed>|null $imageInfo
     * @param-out array<string,mixed> $imageInfo
     * @return array<int|string,mixed>|false
     */
    public function pwgSafeGetimagesize(string $filename, ?array &$imageInfo = null): array|false
    {
        set_error_handler(static fn (): bool => true);
        try {
            $result = getimagesize($filename, $imageInfo);
        } finally {
            restore_error_handler();
        }
        if ($imageInfo === null) {
            $imageInfo = [];
        }
        return $result;
    }

    /** @return array<string,mixed>|false */
    public function pwgSafeExifReadData(string $filename): array|false
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

    public function originalToRepresentative(string $path, string $representativeExt): string
    {
        $pos  = strrpos($path, '/');
        $path = substr_replace($path, 'pwg_representative/', $pos + 1, 0);
        $pos  = strrpos($path, '.');
        return substr_replace($path, $representativeExt, $pos + 1);
    }

    public function originalToFormat(string $path, string $formatExt): string
    {
        $pos  = strrpos($path, '/');
        $path = substr_replace($path, 'pwg_format/', $pos + 1, 0);
        $pos  = strrpos($path, '.');
        return substr_replace($path, $formatExt, $pos + 1);
    }

    /** @param array<string,mixed> $elementInfo */
    public function getElementPath(array $elementInfo): string
    {
        $path = is_scalar($elementInfo['path']) ? (string) $elementInfo['path'] : '';
        if (!UrlService::urlIsRemote($path)) {
            $path = PHPWG_ROOT_PATH . $path;
        }
        return $path;
    }

    public static function generateKey(int $size): string
    {
        $bytes = random_bytes(max(1, $size + 10));
        return substr(str_replace(['+', '/'], '', base64_encode($bytes)), 0, $size);
    }

    public static function scriptBasename(): string
    {
        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $value) {
            if (!empty($_SERVER[$value])) {
                $filename = strtolower(is_scalar($_SERVER[$value]) ? (string) $_SERVER[$value] : '');
                $basename = basename($filename, '.php');
                if (!empty($basename)) {
                    return $basename;
                }
            }
        }
        return '';
    }

    public function getBranchFromVersion(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 1));
    }

    public function urlCheckFormat(string $url): bool
    {
        if (str_contains($url, '"')) {
            return false;
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function emailCheckFormat(string $mailAddress): bool
    {
        return filter_var($mailAddress, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function safeVersionCompare(mixed $a, mixed $b, mixed $op = null): int|bool
    {
        $replaceChars = static fn (array $m): string => (string) ord((strtolower((string) ($m[1] ?? ''))[0] ?? '')[0]);
        $aStr = is_scalar($a) ? (string) $a : '';
        $bStr = is_scalar($b) ? (string) $b : '';
        $aStr = (string) preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $aStr);
        $bStr = (string) preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $bStr);
        $aStr = (string) preg_replace_callback('#\b([a-z]{1})\b#i', $replaceChars, $aStr);
        $bStr = (string) preg_replace_callback('#\b([a-z]{1})\b#i', $replaceChars, $bStr);
        if (empty($op)) {
            return version_compare($aStr, $bStr);
        }
        return version_compare($aStr, $bStr, is_scalar($op) ? (string) $op : '');
    }

    public function isValidMysqlDatetime(string $datetime): bool
    {
        $format = 'Y-m-d H:i:s';
        $date   = \DateTime::createFromFormat($format, $datetime);
        if ($date && $date->format($format) === $datetime) {
            return true;
        }
        $format = 'Y-m-d';
        $date   = \DateTime::createFromFormat($format, $datetime);
        return $date && $date->format($format) === $datetime;
    }

    public function secureDirectory(string $dir): void
    {
        $file = $dir . '/index.htm';
        if (!file_exists($file) && is_writable($dir)) {
            file_put_contents($file, 'Not allowed!');
        }
    }

    /** @return array{0: string, 1: string|null} */
    public function getContainerInfo(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 5)) === 'LINUX' && empty(ini_get('open_basedir'))) {
            if (file_exists('/proc/2/sched')) {
                $file = file_get_contents('/proc/2/sched');
                if ($file && str_starts_with($file, 'kthreadd')) {
                    return ['none', null];
                }
            }
            $infoFilePath      = '/var/www/html/piwigo-docker.info';
            $infoFileLinuxserver = '/build_version';
            if (is_readable($infoFilePath)) {
                $fileLines = file($infoFilePath);
                if (is_array($fileLines) && 'Official Piwigo container' === trim($fileLines[0])) {
                    $containerVersion = null;
                    if (preg_match('/^Build Version (.*)$/', $fileLines[count($fileLines) - 1], $matches)) {
                        $containerVersion = $matches[1];
                    }
                    return ['Official', $containerVersion];
                }
            } elseif (is_readable($infoFileLinuxserver)) {
                $fileLines = file($infoFileLinuxserver);
                if (is_array($fileLines) && str_starts_with($fileLines[0], 'Linuxserver.io')) {
                    $containerVersion = null;
                    if (preg_match('/version:\s*(.*)$/', $fileLines[0], $matches)) {
                        $containerVersion = $matches[1];
                    }
                    return ['LinuxServer.io', $containerVersion];
                }
            }
            return ['Unknown', null];
        }
        return ['none', null];
    }
}
