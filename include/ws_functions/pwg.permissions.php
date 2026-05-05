<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\PermissionsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_permissions_getList(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(PermissionsEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_permissions_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(PermissionsEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_permissions_remove(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(PermissionsEndpoints::class)->remove($params, $service);
}
