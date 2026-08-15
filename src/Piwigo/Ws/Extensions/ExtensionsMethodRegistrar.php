<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Piwigo\Core\WsParamType;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;

/**
 * Registers every `pwg.extensions*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class ExtensionsMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.plugins.getList',
            handlerClass: PluginsGetListHandler::class,
            description: 'Gets the list of plugins with id, name, version, state and description.',
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.plugins.performAction',
            handlerClass: PluginsPerformActionHandler::class,
            params: [
                ParamDefinition::required('action', info: 'install, activate, deactivate, uninstall, delete'),
                ParamDefinition::required('plugin'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.themes.performAction',
            handlerClass: ThemesPerformActionHandler::class,
            params: [
                ParamDefinition::required('action', info: 'activate, deactivate, delete, set_default'),
                ParamDefinition::required('theme'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.update',
            handlerClass: UpdateHandler::class,
            description: '<b>Webmaster only.</b>',
            params: [
                ParamDefinition::required('type', info: 'plugins, languages, themes'),
                ParamDefinition::required('id'),
                ParamDefinition::required('revision'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.ignoreUpdate',
            handlerClass: IgnoreUpdateHandler::class,
            description: '<b>Webmaster only.</b> Ignores an extension if it needs update.',
            params: [
                ParamDefinition::optional('type', info: 'plugins, languages, themes'),
                ParamDefinition::optional('id'),
                ParamDefinition::optional('reset', false, WsParamType::BOOL, info: 'If true, all ignored extensions will be reinitilized.'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.checkUpdates',
            handlerClass: CheckUpdatesHandler::class,
            description: 'Checks if piwigo or extensions are up to date.',
            requiresAuth: true,
        ));
    }
}
