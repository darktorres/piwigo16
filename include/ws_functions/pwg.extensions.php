<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\ExtensionsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_plugins_getList(array $params, \Piwigo\Ws\PwgServer $service): array
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->pluginsGetList($params, $service);
}

/** @param array<mixed> $params */
function ws_plugins_performAction(array $params, \Piwigo\Ws\PwgServer $service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->pluginsPerformAction($params, $service);
}

/** @param array<mixed> $params */
function ws_themes_performAction(array $params, \Piwigo\Ws\PwgServer $service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->themesPerformAction($params, $service);
}

/** @param array<mixed> $params */
function ws_extensions_update(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->update($params, $service);
}

/** @param array<mixed> $params */
function ws_extensions_ignoreupdate(array $params, \Piwigo\Ws\PwgServer $service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->ignoreUpdate($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_extensions_checkupdates(array $params, \Piwigo\Ws\PwgServer $service): array
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->checkUpdates($params, $service);
}
