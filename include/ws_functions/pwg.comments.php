<?php

declare(strict_types=1);

use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgError;
use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\CommentsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+
/**
 * @param array<mixed> $params
 * @return array<mixed>|PwgError
 */
function ws_userComments_getList(array $params, PwgServer &$service): PwgError|array
{
    return ServiceLocator::get(CommentsEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_userComments_delete(array $params, PwgServer &$service): PwgError|string
{
    return ServiceLocator::get(CommentsEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_userComments_validate(array $params, PwgServer &$service): PwgError|string
{
    return ServiceLocator::get(CommentsEndpoints::class)->validate($params, $service);
}
