<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+
/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_categories_getImages(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getImages($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_categories_getList(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_categories_getAdminList(array $params, PwgServer &$service): array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getAdminList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_categories_add(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setRank(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setRank($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setInfo(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setRepresentative(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setRepresentative($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_deleteRepresentative(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->deleteRepresentative($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_categories_refreshRepresentative(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->refreshRepresentative($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_delete(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->delete($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_categories_move(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->move($params, $service);
}

/** @param array<mixed> $param */
function ws_categories_calculateOrphans(array $param, PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->calculateOrphans($param, $service);
}
