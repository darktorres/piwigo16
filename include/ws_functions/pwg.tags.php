<?php

declare(strict_types=1);

use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgError;
use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\TagsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_tags_getList(array $params, PwgServer &$service): array
{
    return ServiceLocator::get(TagsEndpoints::class)->getList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_tags_getAdminList(array $params, PwgServer &$service): array
{
    return ServiceLocator::get(TagsEndpoints::class)->getAdminList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_tags_getImages(array $params, PwgServer &$service): array
{
    return ServiceLocator::get(TagsEndpoints::class)->getImages($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_tags_add(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(TagsEndpoints::class)->add($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_tags_delete(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(TagsEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_tags_rename(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(TagsEndpoints::class)->rename($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_tags_duplicate(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(TagsEndpoints::class)->duplicate($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_tags_merge(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(TagsEndpoints::class)->merge($params, $service);
}
