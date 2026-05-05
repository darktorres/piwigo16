<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Piwigo\Exception\ConfigException;
use Piwigo\Config\Config;
use Psr\Log\LoggerInterface;

final readonly class MetadataService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns information from IPTC metadata; mapping is done here.
     *
     * @param array<string,string> $map
     * @return array<mixed>
     */
    public function getIptcData(string $filename, array $map, string $arraySep = ','): array
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
                foreach (array_keys($rmap) as $iptcKey) {
                    if (isset($iptc[$iptcKey][0])) {
                        if ($iptcKey == '2#025') {
                            $value = implode(
                                $arraySep,
                                array_map($this->cleanIptcValue(...), $iptc[$iptcKey])
                            );
                        } else {
                            $value = $this->cleanIptcValue($iptc[$iptcKey][0]);
                        }

                        foreach (array_keys($map, $iptcKey) as $pwgKey) {
                            $result[$pwgKey] = $value;

                            if (!Config::allowHtmlInMetadata()) {
                                $result[$pwgKey] = strip_tags($result[$pwgKey]);
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function cleanIptcValue(string $value): string
    {
        // strip leading zeros (weird Kodak Scanner software)
        while (isset($value[0]) and $value[0] == chr(0)) {
            $value = substr($value, 1);
        }
        // remove binary nulls
        $value = str_replace(chr(0x00), ' ', $value);

        if (preg_match('/[\x80-\xff]/', $value)) {
            $value = (string) trigger_change('clean_iptc_value', $value);
            if (($qual = qualify_utf8($value)) != 0) {
                if ($qual > 0) {
                    $inputEncoding = 'utf-8';
                } else {
                    $inputEncoding = 'iso-8859-1';
                    if (function_exists('iconv') or function_exists('mb_convert_encoding')) {
                        $inputEncoding = 'windows-1252';
                    }
                }

                $value = convert_charset($value, $inputEncoding, get_pwg_charset());
            }
        }
        return $value;
    }

    /**
     * Returns information from EXIF metadata; mapping is done here.
     *
     * @param array<string,string> $map
     * @return array<mixed>
     */
    public function getExifData(string $filename, array $map): array
    {
        $result = [];

        if (!function_exists('exif_read_data')) {
            throw new ConfigException('Exif extension not available, admin should disable exif use');
        }

        $exif = pwg_safe_exif_read_data($filename) ?: null;
        $exif = trigger_change('format_exif_data', $exif, $filename, $map);
        if (!empty($exif)) {

            foreach ($map as $key => $field) {
                if (!str_contains((string) $field, ';')) {
                    if (isset($exif[$field])) {
                        $result[$key] = $exif[$field];
                    }
                } else {
                    $tokens      = explode(';', (string) $field);
                    $exifSection = $exif[$tokens[0]] ?? null;
                    if (is_array($exifSection) && isset($exifSection[$tokens[1]])) {
                        $result[$key] = $exifSection[$tokens[1]];
                    }
                }
            }

            // GPS data
            $gpsExif = array_intersect_key($exif, array_flip(['GPSLatitudeRef', 'GPSLatitude', 'GPSLongitudeRef', 'GPSLongitude']));
            if (count($gpsExif) == 4) {
                $gpsLatArr = is_array($gpsExif['GPSLatitude']) ? $gpsExif['GPSLatitude'] : null;
                $gpsLonArr = is_array($gpsExif['GPSLongitude']) ? $gpsExif['GPSLongitude'] : null;
                if (
                    $gpsLatArr !== null and in_array($gpsExif['GPSLatitudeRef'], ['S', 'N']) and
                    $gpsLonArr !== null and in_array($gpsExif['GPSLongitudeRef'], ['W', 'E'])
                ) {
                    $gpsLatStr = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $gpsLatArr);
                    $gpsLonStr = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $gpsLonArr);
                    $gpsLatRef = is_scalar($gpsExif['GPSLatitudeRef']) ? (string) $gpsExif['GPSLatitudeRef'] : '';
                    $gpsLonRef = is_scalar($gpsExif['GPSLongitudeRef']) ? (string) $gpsExif['GPSLongitudeRef'] : '';
                    $latitude  = $this->parseExifGpsData($gpsLatStr, $gpsLatRef);
                    $longitude = $this->parseExifGpsData($gpsLonStr, $gpsLonRef);

                    if ($latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0) {
                        $result['latitude']  = $latitude;
                        $result['longitude'] = $longitude;
                    } else {
                        $this->logger->info('[' . __METHOD__ . '][filename=' . $filename . '] invalid GPS coordinates, latitude=' . $latitude . ' longitude=' . $longitude);
                    }
                }
            }
        }

        if (!Config::allowHtmlInMetadata()) {
            foreach ($result as $key => $value) {
                if (is_array($value)) {
                    array_walk_recursive($value, $this->stripHtmlInMetadata(...));
                } else {
                    $result[$key] = strip_tags(is_scalar($value) ? (string) $value : '');
                }
            }
        }

        return $result;
    }

    public function stripHtmlInMetadata(mixed &$v, string $k): void
    {
        $v = strip_tags(is_scalar($v) ? (string) $v : '');
    }

    /**
     * Converts EXIF GPS format to a float value.
     *
     * @param array<string> $raw e.g. ['41/1', '54/1', '9843/500']
     * @param string $ref 'S', 'N', 'E', or 'W'
     */
    public function parseExifGpsData(array $raw, string $ref): float
    {
        $parts = [];
        foreach ($raw as $segment) {
            $tokens   = explode('/', $segment);
            $parts[]  = isset($tokens[1]) && (float) $tokens[1] !== 0.0
                ? (float) $tokens[0] / (float) $tokens[1]
                : 0.0;
        }

        $v = ($parts[0] ?? 0.0) + ($parts[1] ?? 0.0) / 60.0 + ($parts[2] ?? 0.0) / 3600.0;

        $ref = strtoupper($ref);
        if ($ref === 'S' || $ref === 'W') {
            $v = -$v;
        }

        return $v;
    }
}
