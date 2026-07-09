<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * returns informations from IPTC metadata, mapping is done in this function.
 *
 * @param string $filename
 * @param array<string, string> $map
 * @return array<string, string>
 */
function get_iptc_data($filename, $map, string $array_sep = ','): array
{
    global $conf;

    $result = [];

    $imginfo = [];
    if (@getimagesize($filename, $imginfo) == false) {
        return $result;
    }

    if (isset($imginfo['APP13'])) {
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

                        if (! $conf['allow_html_in_metadata']) {
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
 * @param string $filename
 * @param array<string, string> $map
 * @return array<string, mixed>
 */
function get_exif_data($filename, $map): array
{
    global $conf, $logger;

    $result = [];

    if (! function_exists('exif_read_data')) {
        die('Exif extension not available, admin should disable exif use');
    }

    // Read EXIF data
    if ($exif = @exif_read_data($filename) or $exif2 = trigger_change('format_exif_data', $exif = null, $filename, $map)) {
        if (! empty($exif2)) {
            $exif = $exif2;
        } else {
            $exif = trigger_change('format_exif_data', $exif, $filename, $map);
        }

        // configured fields
        foreach ($map as $key => $field) {
            if (! str_contains((string) $field, ';')) {
                if (isset($exif[$field])) {
                    $result[$key] = $exif[$field];
                }
            } else {
                $tokens = explode(';', (string) $field);
                if (isset($exif[$tokens[0]][$tokens[1]])) {
                    $result[$key] = $exif[$tokens[0]][$tokens[1]];
                }
            }
        }

        // GPS data
        $gps_exif = array_intersect_key($exif, array_flip(['GPSLatitudeRef', 'GPSLatitude', 'GPSLongitudeRef', 'GPSLongitude']));
        if (count($gps_exif) == 4) {
            if (
                is_array($gps_exif['GPSLatitude']) and in_array($gps_exif['GPSLatitudeRef'], ['S', 'N']) and
                is_array($gps_exif['GPSLongitude']) and in_array($gps_exif['GPSLongitudeRef'], ['W', 'E'])
            ) {
                $latitude = parse_exif_gps_data($gps_exif['GPSLatitude'], $gps_exif['GPSLatitudeRef']);
                $longitude = parse_exif_gps_data($gps_exif['GPSLongitude'], $gps_exif['GPSLongitudeRef']);

                if ($latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0) {
                    $result['latitude'] = $latitude;
                    $result['longitude'] = $longitude;
                } else {
                    $logger->info('[' . __FUNCTION__ . '][filename=' . $filename . '] invalid GPS coordinates, latitude=' . $latitude . ' longitude=' . $longitude);
                }
            }
        }
    }

    if (! $conf['allow_html_in_metadata']) {
        foreach ($result as $key => $value) {
            // in case the origin of the photo is unsecure (user upload), we remove
            // HTML tags to avoid XSS (malicious execution of javascript)
            if (is_array($value)) {
                array_walk_recursive($value, strip_html_in_metadata(...));
            } else {
                $result[$key] = strip_tags((string) $value);
            }
        }
    }

    return $result;
}

function strip_html_in_metadata(mixed &$v, int|string $k): void
{
    $v = strip_tags((string) $v);
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
function parse_exif_gps_data(array $raw, $ref): float|int
{
    $parsed = [];
    foreach ($raw as $component) {
        $parts = explode('/', $component);
        $parsed[] = $parts[1] == 0 ? 0 : (float) $parts[0] / (float) $parts[1];
    }

    $v = $parsed[0] + $parsed[1] / 60 + $parsed[2] / 3600;

    $ref = strtoupper($ref);
    if ($ref == 'S' or $ref == 'W') {
        $v = -$v;
    }

    return $v;
}
