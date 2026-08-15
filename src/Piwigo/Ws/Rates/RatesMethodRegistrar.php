<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Rates;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.rates*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class RatesMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.rates.delete',
            handlerClass: DeleteHandler::class,
            description: 'Deletes all rates for a user.',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::optional('anonymous_id'),
                ParamDefinition::optionalFlag('image_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
