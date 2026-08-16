<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Permissions;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.permissions*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class PermissionsMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.permissions.getList',
            handlerClass: GetListHandler::class,
            description: 'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
    <br>Provide only one parameter!',
            params: [
                ParamDefinition::optionalFlag('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.permissions.add',
            handlerClass: AddHandler::class,
            description: 'Adds permissions to an album.',
            params: [
                ParamDefinition::required('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('recursive', false, WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.permissions.remove',
            handlerClass: RemoveHandler::class,
            description: 'Removes permissions from an album.',
            params: [
                ParamDefinition::required('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
