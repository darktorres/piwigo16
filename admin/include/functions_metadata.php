<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

require_once(PHPWG_ROOT_PATH . '/include/functions_metadata.inc.php');

/** @return array<mixed> */
function get_sync_iptc_data(string $file): array
{
    return ServiceLocator::get(MetadataAdminService::class)->getSyncIptcData($file, Config::useIptcMapping());
}

/** @return array<mixed> */
function get_sync_exif_data(string $file): array
{
    return ServiceLocator::get(MetadataAdminService::class)->getSyncExifData($file);
}

/** @return string[] */
function get_sync_metadata_attributes(): array
{
    return ServiceLocator::get(MetadataAdminService::class)->getSyncMetadataAttributes();
}

/**
 * @param array<mixed> $infos
 * @return array<mixed>|false
 */
function get_sync_metadata(array $infos): array|false
{
    return ServiceLocator::get(MetadataAdminService::class)->getSyncMetadata($infos);
}

/** @param int[] $ids */
function sync_metadata(array $ids): void
{
    ServiceLocator::get(MetadataAdminService::class)->syncMetadata($ids);
}

/** @return array<mixed> */
function get_filelist(
    int|string $category_id = '',
    int $site_id = 1,
    bool $recursive = false,
    bool $only_new = false
): array {
    return ServiceLocator::get(MetadataAdminService::class)->getFilelist($category_id, $site_id, $recursive, $only_new);
}

function metadata_normalize_keywords_string(string $keywords_string): string
{
    return ServiceLocator::get(MetadataAdminService::class)->normalizeKeywordsString($keywords_string);
}
