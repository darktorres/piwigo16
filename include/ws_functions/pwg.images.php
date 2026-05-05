<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// Internal helpers (also used by other ws files via delegate)
function ws_add_image_category_relations(mixed $image_id, string $categories_string, bool $replace_mode = false): true|PwgError
{
    return ServiceLocator::get(ImagesEndpoints::class)->addImageCategoryRelations($image_id, $categories_string, $replace_mode);
}

function merge_chunks(string $output_filepath, string $original_sum, string $type): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->mergeChunks($output_filepath, $original_sum, $type);
}

function remove_chunks(string $original_sum, string $type): void
{
    ServiceLocator::get(ImagesEndpoints::class)->removeChunks($original_sum, $type);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_addComment(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->addComment($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_getInfo(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->getInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_images_rate(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->rate($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_images_search(array $params, PwgServer $service): array
{
    return ServiceLocator::get(ImagesEndpoints::class)->search($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_filteredSearch_create(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->filteredSearchCreate($params, $service);
}

/** @param array<mixed> $params */
function ws_images_setPrivacyLevel(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->setPrivacyLevel($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_setRank(array $params, PwgServer $service): array|PwgError
{
    return ServiceLocator::get(ImagesEndpoints::class)->setRank($params, $service);
}

/** @param array<mixed> $params */
function ws_images_add_chunk(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->addChunk($params, $service);
}

/** @param array<mixed> $params */
function ws_images_addFile(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->addFile($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_add(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->add($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_addSimple(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->addSimple($params, $service);
}

/** @param array<mixed> $params */
function ws_images_upload(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->upload($params, $service);
}

/** @param array<mixed> $params */
function ws_images_uploadAsync(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->uploadAsync($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_images_exist(array $params, PwgServer $service): array
{
    return ServiceLocator::get(ImagesEndpoints::class)->exist($params, $service);
}

/** @param array<mixed> $params */
function ws_images_formats_searchImage(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->formatsSearchImage($params, $service);
}

/** @param array<mixed> $params */
function ws_images_formats_delete(array $params, PwgServer $service): PwgError|bool
{
    return ServiceLocator::get(ImagesEndpoints::class)->formatsDelete($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_checkFiles(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->checkFiles($params, $service);
}

/** @param array<mixed> $params */
function ws_images_setInfo(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_images_delete(array $params, PwgServer $service): PwgError|int
{
    return ServiceLocator::get(ImagesEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_images_checkUpload(mixed $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->checkUpload($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_images_emptyLounge(array $params, PwgServer $service): array
{
    return ServiceLocator::get(ImagesEndpoints::class)->emptyLounge($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_uploadCompleted(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->uploadCompleted($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_setMd5sum(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->setMd5sum($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_syncMetadata(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->syncMetadata($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_images_deleteOrphans(array $params, PwgServer $service): PwgError|array
{
    return ServiceLocator::get(ImagesEndpoints::class)->deleteOrphans($params, $service);
}

/** @param array<mixed> $params */
function ws_images_setCategory(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ImagesEndpoints::class)->setCategory($params, $service);
}
