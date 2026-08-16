<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\SharedImageFilterParams;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.core*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class CoreMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.getVersion',
            handlerClass: GetVersionHandler::class,
            description: 'Returns the Piwigo version.',
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.getInfos',
            handlerClass: GetInfosHandler::class,
            description: 'Returns general informations.',
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.getCacheSize',
            handlerClass: GetCacheSizeHandler::class,
            description: 'Returns general informations.',
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.caddie.add',
            handlerClass: CaddieAddHandler::class,
            description: 'Adds elements to the caddie. Returns the number of elements added.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.getMissingDerivatives',
            handlerClass: GetMissingDerivativesHandler::class,
            description: 'Returns a list of derivatives to build.',
            params: [
                ParamDefinition::optional('types', null, flags: WsParamFlag::FORCE_ARRAY, info: 'square, thumb, 2small, xsmall, small, medium, large, xlarge, xxlarge, 3xlarge, 4xlarge'),
                ParamDefinition::optional('ids', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('max_urls', 200, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('prev_page', null, WsParamType::INT | WsParamType::POSITIVE),
                ...SharedImageFilterParams::get(),
            ],
            requiresAuth: true,
        ));
    }
}
