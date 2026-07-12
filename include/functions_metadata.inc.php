<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\DbConnection;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

/**
 * returns informations from IPTC metadata, mapping is done in this function.
 *
 * @param string $filename
 * @param array<string, string> $map
 * @return array<string, string>
 */
function get_iptc_data($filename, $map, string $array_sep = ','): array
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getIptcData($filename, $map, $array_sep);
}

/**
 * return a cleaned IPTC value.
 *
 * @param string $value
 */
function clean_iptc_value($value): string
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->cleanIptcValue($value);
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
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getExifData($filename, $map);
}

function strip_html_in_metadata(mixed &$v, int|string $k): void
{
    new MetadataService(new MetadataRepository(DbConnection::build()))->stripHtmlInMetadata($v, $k);
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
    return new MetadataService(new MetadataRepository(DbConnection::build()))->parseExifGpsData(array_values($raw), $ref);
}
