<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\CommentsEndpoints;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/**
 * @param array<mixed> $params
 * @return array<mixed>|\Piwigo\Ws\PwgError
 */
function ws_userComments_getList(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|array
{
    return ServiceLocator::get(CommentsEndpoints::class)->getList($params, $service);
}

/** @param array<mixed> $params */
function ws_userComments_delete(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|string
{
    return ServiceLocator::get(CommentsEndpoints::class)->delete($params, $service);
}

/** @param array<mixed> $params */
function ws_userComments_validate(array $params, \Piwigo\Ws\PwgServer &$service): \Piwigo\Ws\PwgError|string
{
    return ServiceLocator::get(CommentsEndpoints::class)->validate($params, $service);
}
