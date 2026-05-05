<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Metadata\MetadataService;
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
