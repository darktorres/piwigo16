<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\GeneralEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

function ws_directory_size_bytes(string $path): ?int
{
    return ServiceLocator::get(GeneralEndpoints::class)->directorySizeBytes($path);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_getMissingDerivatives(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(GeneralEndpoints::class)->getMissingDerivatives($params, $service);
}

function ws_getVersion(mixed $params, \Piwigo\Ws\PwgServer &$service): string
{
    return ServiceLocator::get(GeneralEndpoints::class)->getVersion($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_getInfos(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    return ServiceLocator::get(GeneralEndpoints::class)->getInfos($params, $service);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_getCacheSize(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    return ServiceLocator::get(GeneralEndpoints::class)->getCacheSize($params, $service);
}

/** @param array<mixed> $params */
function ws_caddie_add(array $params, \Piwigo\Ws\PwgServer &$service): int
{
    return ServiceLocator::get(GeneralEndpoints::class)->caddieAdd($params, $service);
}

/** @param array<mixed> $params */
function ws_rates_delete(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GeneralEndpoints::class)->ratesDelete($params, $service);
}

/** @param array<mixed> $params */
function ws_session_login(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(GeneralEndpoints::class)->sessionLogin($params, $service);
}

function ws_session_logout(mixed $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|true
{
    return ServiceLocator::get(GeneralEndpoints::class)->sessionLogout($params, $service);
}

function ws_session_getStatus(mixed $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    return ServiceLocator::get(GeneralEndpoints::class)->sessionGetStatus($params, $service);
}

/**
 * @param array<mixed> $param
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_getActivityList(array $param, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(GeneralEndpoints::class)->getActivityList($param, $service);
}

/** @param array<mixed> $params */
function ws_history_log(array $params, \Piwigo\Ws\PwgServer &$service): void
{
    ServiceLocator::get(GeneralEndpoints::class)->historyLog($params, $service);
}

/**
 * @param array<mixed> $param
 * @return array<mixed>
 */
function ws_history_search(array $param, \Piwigo\Ws\PwgServer &$service): array
{
    return ServiceLocator::get(GeneralEndpoints::class)->historySearch($param, $service);
}
