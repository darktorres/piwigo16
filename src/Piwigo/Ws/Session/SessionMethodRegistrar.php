<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Session;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;

/**
 * Registers every `pwg.session*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class SessionMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.session.getStatus',
            handlerClass: GetStatusHandler::class,
            description: 'Gets information about the current session. Also provides a token useable with admin methods.',
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.session.login',
            handlerClass: LoginHandler::class,
            description: 'Tries to login the user.',
            params: [
                ParamDefinition::required('username'),
                ParamDefinition::optional('password'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.session.logout',
            handlerClass: LogoutHandler::class,
            description: 'Ends the current session.',
        ));
    }
}
