<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\GroupsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_groups_getList(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(GroupsEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_delete(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|\Piwigo\Ws\PwgNamedArray
{
    return ServiceLocator::get(GroupsEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_addUser(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->addUser($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_groups_merge(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(GroupsEndpoints::class)->merge($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_duplicate(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->duplicate($params, $service);
}

/** @param array<mixed> $params */
function ws_groups_deleteUser(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GroupsEndpoints::class)->deleteUser($params, $service);
}
