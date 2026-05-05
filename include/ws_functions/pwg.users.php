<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\UsersEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_users_getList(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(UsersEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_users_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->add($params, $service);
}

/** @param array<mixed> $params */
function ws_users_getAuthKey(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->getAuthKey($params, $service);
}

/** @param array<mixed> $params */
function ws_users_delete(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|string
{
    return ServiceLocator::get(UsersEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_users_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->setInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_users_setMyInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->setMyInfo($params, $service);
}

/** @param array<mixed> $params */
function ws_users_preferences_set(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->preferencesSet($params, $service);
}

/** @param array<mixed> $params */
function ws_users_favorites_add(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(UsersEndpoints::class)->favoritesAdd($params, $service);
}

/** @param array<mixed> $params */
function ws_users_favorites_remove(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(UsersEndpoints::class)->favoritesRemove($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|false
 */
function ws_users_favorites_getList(array $params, \Piwigo\Ws\PwgServer &$service): false|array
{
    return ServiceLocator::get(UsersEndpoints::class)->favoritesGetList($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_users_generate_password_link(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(UsersEndpoints::class)->generatePasswordLink($params, $service);
}

/** @param array<mixed> $params */
function ws_set_main_user(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|string
{
    return ServiceLocator::get(UsersEndpoints::class)->setMainUser($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_create_api_key(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(UsersEndpoints::class)->createApiKey($params, $service);
}

/** @param array<mixed> $params */
function ws_revoke_api_key(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->revokeApiKey($params, $service);
}

/** @param array<mixed> $params */
function ws_edit_api_key(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(UsersEndpoints::class)->editApiKey($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_get_api_key(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(UsersEndpoints::class)->getApiKey($params, $service);
}
