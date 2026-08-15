<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Activity;

use Piwigo\Core\WsParamType;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;

/**
 * Registers every `pwg.activity*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class ActivityMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.activity.getList',
            handlerClass: GetListHandler::class,
            description: 'Returns general informations.',
            params: [
                ParamDefinition::optional('page', null, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('offset', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('uid', null, WsParamType::ID),
                ParamDefinition::optional('date_min'),
                ParamDefinition::optional('date_max'),
                ParamDefinition::optional('id', null, WsParamType::ID),
                ParamDefinition::optional('object'),
                ParamDefinition::optional('action'),
            ],
            requiresAuth: true,
        ));

        $service->addMethod(
            'pwg.activity.downloadLog',
            // 'ws_activity_downloadLog' is not a defined function -- this
            // registration fatals with "call to undefined function" if
            // ever invoked. Permanently dead -- Group 19's Core batch
            // leaves this on the legacy addMethod() path, not a Handler
            // (see tests/Contract/WsHistoryTest.php's own regression
            // coverage for this exact behavior).
            'ws_activity_downloadLog',
            null,
            'Returns general informations.',
            options: [
                'admin_only' => true,
            ]
        );
    }
}
