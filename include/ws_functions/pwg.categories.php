<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\CategoriesEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_categories_getImages(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getImages($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_categories_getList(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_categories_getAdminList(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->getAdminList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_categories_add(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setRank(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setRank($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_setRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->setRepresentative($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_deleteRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->deleteRepresentative($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_categories_refreshRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->refreshRepresentative($params, $service);
}

/** @param array<mixed> $params */
function ws_categories_delete(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->delete($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_categories_move(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CategoriesEndpoints::class)->move($params, $service);
}

/** @param array<mixed> $param */
function ws_categories_calculateOrphans(array $param, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(CategoriesEndpoints::class)->calculateOrphans($param, $service);
}
