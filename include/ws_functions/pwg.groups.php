<?php

declare(strict_types=1);

use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\GroupsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+
/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_groups_getList(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(GroupsEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_add(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
{
    return ServiceLocator::get(GroupsEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_setInfo(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_addUser(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->addUser($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_groups_merge(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(GroupsEndpoints::class)->merge($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_duplicate(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->duplicate($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_deleteUser(array $params, PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->deleteUser($params, $service);
}
