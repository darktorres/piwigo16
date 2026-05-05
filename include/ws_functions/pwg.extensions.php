<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\ExtensionsEndpoints;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_plugins_getList(array $params, PwgServer $service): array
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->pluginsGetList($params, $service);
}

/** @param array<mixed> $params */
function ws_plugins_performAction(array $params, PwgServer $service): PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->pluginsPerformAction($params, $service);
}

/** @param array<mixed> $params */
function ws_themes_performAction(array $params, PwgServer $service): PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->themesPerformAction($params, $service);
}

/** @param array<mixed> $params */
function ws_extensions_update(array $params, PwgServer $service): mixed
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->update($params, $service);
}

/** @param array<mixed> $params */
function ws_extensions_ignoreupdate(array $params, PwgServer $service): PwgError|true
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->ignoreUpdate($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_extensions_checkupdates(array $params, PwgServer $service): array
{
    return ServiceLocator::get(ExtensionsEndpoints::class)->checkUpdates($params, $service);
}
