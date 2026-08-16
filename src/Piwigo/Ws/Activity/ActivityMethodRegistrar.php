<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Activity;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamType;

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
        $service->register(MethodDefinition::forHandler(
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

        $service->register(MethodDefinition::forLegacyCallback(
            name: 'pwg.activity.downloadLog',
            // 'ws_activity_downloadLog' is not a defined function -- this
            // registration fatals with "call to undefined function" if
            // ever invoked. Permanently dead -- Group 19's Core batch
            // leaves this on the legacy callback path, not a Handler (see
            // tests/Contract/WsHistoryTest.php's own regression coverage
            // for this exact behavior). PHPStan runs at level 10 (max),
            // which would flag a real call to an undefined function inside
            // a Handler's own body -- the callback stays an opaque string,
            // invisible to that check, same as it always was.
            callback: 'ws_activity_downloadLog',
            description: 'Returns general informations.',
            requiresAuth: true,
        ));
    }
}
