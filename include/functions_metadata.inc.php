<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\metadata
 */
/**
 * returns informations from IPTC metadata, mapping is done in this function.
 *
 * @param string $filename
 * @param array $map
 */
/**
 * @param array<string,string> $map
 * @return array<mixed>
 */
function get_iptc_data(string $filename, array $map, string $array_sep = ','): array
{
    $result = [];

    $imginfo = [];
    if (false === pwg_safe_getimagesize($filename, $imginfo)) {
        return $result;
    }

    if (isset($imginfo['APP13']) && is_string($imginfo['APP13'])) {
        $iptc = iptcparse($imginfo['APP13']);
        if (is_array($iptc)) {
            $rmap = array_flip($map);
            foreach (array_keys($rmap) as $iptc_key) {
                if (isset($iptc[$iptc_key][0])) {
                    if ($iptc_key == '2#025') {
                        $value = implode(
                            $array_sep,
                            array_map(clean_iptc_value(...), $iptc[$iptc_key])
                        );
                    } else {
                        $value = clean_iptc_value($iptc[$iptc_key][0]);
                    }

                    foreach (array_keys($map, $iptc_key) as $pwg_key) {
                        $result[$pwg_key] = $value;

                        if (!\Piwigo\Config\Config::allowHtmlInMetadata()) {
                            // in case the origin of the photo is unsecure (user upload), we
                            // remove HTML tags to avoid XSS (malicious execution of
                            // javascript)
                            $result[$pwg_key] = strip_tags($result[$pwg_key]);
                        }
                    }
                }
            }
        }
    }
    return $result;
}

/**
 * return a cleaned IPTC value.
 *
 * @param string $value
 * @return string
 */
function clean_iptc_value($value)
{
    // strip leading zeros (weird Kodak Scanner software)
    while (isset($value[0]) and $value[0] == chr(0)) {
        $value = substr($value, 1);
    }
    // remove binary nulls
    $value = str_replace(chr(0x00), ' ', $value);

    if (preg_match('/[\x80-\xff]/', $value)) {
        // apparently mac uses some MacRoman crap encoding. I don't know
        // how to detect it so a plugin should do the trick.
        $value = trigger_change('clean_iptc_value', $value);
        if (($qual = qualify_utf8($value)) != 0) {// has non ascii chars
            if ($qual > 0) {
                $input_encoding = 'utf-8';
            } else {
                $input_encoding = 'iso-8859-1';
                if (function_exists('iconv') or function_exists('mb_convert_encoding')) {
                    // using windows-1252 because it supports additional characters
                    // such as "oe" in a single character (ligature). About the
                    // difference between Windows-1252 and ISO-8859-1: the characters
                    // 0x80-0x9F will not convert correctly. But these are control
                    // characters which are almost never used.
                    $input_encoding = 'windows-1252';
                }
            }

            $value = convert_charset($value, $input_encoding, get_pwg_charset());
        }
    }
    return $value;
}

/**
 * returns informations from EXIF metadata, mapping is done in this function.
 *
 * @param array $map
 */
/**
 * @param array<string,string> $map
 * @return array<mixed>
 */
function get_exif_data(string $filename, array $map): array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $result = [];

    if (!function_exists('exif_read_data')) {
        throw new \Piwigo\Exception\ConfigException('Exif extension not available, admin should disable exif use');
    }

    // Read EXIF data
    $exif = pwg_safe_exif_read_data($filename) ?: null;
    $exif = trigger_change('format_exif_data', $exif, $filename, $map);
    if (!empty($exif)) {

        // configured fields
        foreach ($map as $key => $field) {
            if (!str_contains((string) $field, ';')) {
                if (isset($exif[$field])) {
                    $result[$key] = $exif[$field];
                }
            } else {
                $tokens = explode(';', (string) $field);
                $exif_section = $exif[$tokens[0]] ?? null;
                if (is_array($exif_section) && isset($exif_section[$tokens[1]])) {
                    $result[$key] = $exif_section[$tokens[1]];
                }
            }
        }

        // GPS data
        $gps_exif = array_intersect_key($exif, array_flip(['GPSLatitudeRef', 'GPSLatitude', 'GPSLongitudeRef', 'GPSLongitude']));
        if (count($gps_exif) == 4) {
            $gps_lat_arr = is_array($gps_exif['GPSLatitude']) ? $gps_exif['GPSLatitude'] : null;
            $gps_lon_arr = is_array($gps_exif['GPSLongitude']) ? $gps_exif['GPSLongitude'] : null;
            if (
                $gps_lat_arr !== null and in_array($gps_exif['GPSLatitudeRef'], ['S', 'N']) and
                $gps_lon_arr !== null and in_array($gps_exif['GPSLongitudeRef'], ['W', 'E'])
            ) {
                $gps_lat_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $gps_lat_arr);
                $gps_lon_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $gps_lon_arr);
                $gps_lat_ref = is_scalar($gps_exif['GPSLatitudeRef']) ? (string) $gps_exif['GPSLatitudeRef'] : '';
                $gps_lon_ref = is_scalar($gps_exif['GPSLongitudeRef']) ? (string) $gps_exif['GPSLongitudeRef'] : '';
                $latitude = parse_exif_gps_data($gps_lat_str, $gps_lat_ref);
                $longitude = parse_exif_gps_data($gps_lon_str, $gps_lon_ref);

                if ($latitude >= -90.0  &&  $latitude <= 90.0  &&  $longitude >= -180.0  &&  $longitude <= 180.0) {
                    $result['latitude'] = $latitude;
                    $result['longitude'] = $longitude;
                } else {
                    $logger->info('['.__FUNCTION__.'][filename='.$filename.'] invalid GPS coordinates, latitude='.$latitude.' longitude='.$longitude);
                }
            }
        }
    }

    if (!\Piwigo\Config\Config::allowHtmlInMetadata()) {
        foreach ($result as $key => $value) {
            // in case the origin of the photo is unsecure (user upload), we remove
            // HTML tags to avoid XSS (malicious execution of javascript)
            if (is_array($value)) {
                array_walk_recursive($value, strip_html_in_metadata(...));
            } else {
                $result[$key] = strip_tags(is_scalar($value) ? (string) $value : '');
            }
        }
    }

    return $result;
}

function strip_html_in_metadata(mixed &$v, string $k): void
{
    $v = strip_tags(is_scalar($v) ? (string) $v : '');
}

/**
 * Converts EXIF GPS format to a float value.
 * @since 2.6
 *
 * @param string[] $raw eg:
 *    - 41/1
 *    - 54/1
 *    - 9843/500
 * @param string $ref 'S', 'N', 'E', 'W'. eg: 'N'
 * @return float eg: 41.905468
 */
function parse_exif_gps_data(array $raw, $ref): float
{
    foreach ($raw as &$i) {
        $i = explode('/', $i);
        $i = $i[1] == 0 ? 0 : (float)$i[0] / (float)$i[1];
    }
    unset($i);

    $v = (float) $raw[0] + (float) $raw[1] / 60 + (float) $raw[2] / 3600;

    $ref = strtoupper($ref);
    if ($ref == 'S' or $ref == 'W') {
        $v = -$v;
    }

    return $v;
}
