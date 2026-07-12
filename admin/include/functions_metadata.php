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

include_once PHPWG_ROOT_PATH . '/include/functions_metadata.inc.php';

/**
 * Returns IPTC metadata to sync from a file, depending on IPTC mapping.
 *
 * @param string $file
 * @return array<string, string>
 */
function get_sync_iptc_data($file): array
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getSyncIptcData($file);
}

/**
 * Returns EXIF metadata to sync from a file, depending on EXIF mapping.
 *
 * @param string $file
 * @return array<string, mixed>
 */
function get_sync_exif_data($file): array
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getSyncExifData($file);
}

/**
 * Get all potential file metadata fields, including IPTC and EXIF.
 *
 * @return string[]
 */
function get_sync_metadata_attributes(): array
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getSyncMetadataAttributes();
}

/**
 * Get all metadata of a file.
 *
 * @param array<string, mixed> $infos - (path[, representative_ext])
 * @return array<string, mixed>|false includes data provided in $infos, or false if the
 *   file's size can't be read
 */
function get_sync_metadata($infos)
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))->getSyncMetadata($infos);
}

/**
 * Sync all metadata of a list of images.
 * Metadata are fetched from original files and saved in database.
 *
 * @param int[] $ids
 */
function sync_metadata($ids): void
{
    new MetadataService(new MetadataRepository(DbConnection::build()))->syncMetadata(array_values($ids));
}

/**
 * Returns an array associating element id (images.id) with its complete
 * path in the filesystem
 *
 * @param int|string $category_id numeric category id, or '' for no filter
 * @param int $site_id
 * @param bool $recursive
 * @param bool $only_new
 * @return array<int|string, mixed>
 */
function get_filelist(
    $category_id = '',
    $site_id = 1,
    $recursive = false,
    $only_new = false
): array {
    return new MetadataService(new MetadataRepository(DbConnection::build()))
        ->getFilelist($category_id, $site_id, $recursive, $only_new);
}

/**
 * Returns the list of keywords (future tags) correctly separated with
 * commas. Other separators are converted into commas.
 *
 * @param string $keywords_string
 */
function metadata_normalize_keywords_string($keywords_string): string
{
    return new MetadataService(new MetadataRepository(DbConnection::build()))
        ->metadataNormalizeKeywordsString($keywords_string);
}
