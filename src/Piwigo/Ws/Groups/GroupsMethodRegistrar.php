<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use Piwigo\Config\CurrentConfig;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.groups*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class GroupsMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.getList',
            handlerClass: GetListHandler::class,
            description: 'Retrieves a list of all groups. The list can be filtered.',
            params: [
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('name', info: 'Use "%" as wildcard.'),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxUsersPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', 'name', info: 'id, name, nb_users, is_default'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.add',
            handlerClass: AddHandler::class,
            description: 'Creates a group and returns the new group record.',
            params: [
                ParamDefinition::required('name'),
                ParamDefinition::optional('is_default', false, WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.delete',
            handlerClass: DeleteHandler::class,
            description: 'Deletes a or more groups. Users and photos are not deleted.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.setInfo',
            handlerClass: SetInfoHandler::class,
            description: 'Updates a group. Leave a field blank to keep the current value.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::optionalFlag('name'),
                ParamDefinition::optionalFlag('is_default', WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.addUser',
            handlerClass: AddUserHandler::class,
            description: 'Adds one or more users to a group.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.deleteUser',
            handlerClass: DeleteUserHandler::class,
            description: 'Removes one or more users from a group.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.merge',
            handlerClass: MergeHandler::class,
            description: 'Merge groups in one other group',
            params: [
                ParamDefinition::required('destination_group_id', WsParamType::ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required('merge_group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.groups.duplicate',
            handlerClass: DuplicateHandler::class,
            description: 'Create a copy of a group',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
