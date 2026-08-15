<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.history*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class HistoryMethodRegistrar
{
    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.history.log',
            handlerClass: LogHandler::class,
            description: 'Log visit in history',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('cat_id', null, WsParamType::ID),
                ParamDefinition::optional('section'),
                ParamDefinition::optional('tags_string'),
                ParamDefinition::optional('is_download', false, WsParamType::BOOL),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.history.search',
            handlerClass: SearchHandler::class,
            description: 'Gives an history of who has visited the galery and the actions done in it. Receives parameter.
          <br> <strong>Types </strong> can be : \'none\', \'picture\', \'high\', \'other\'
          <br> <strong>Date format</strong> is yyyy-mm-dd
          <br> <strong>display_thumbnail</strong> can be : \'no_display_thumbnail\', \'display_thumbnail_classic\', \'display_thumbnail_hoverbox\'',
            params: [
                ParamDefinition::optional('start'),
                ParamDefinition::optional('end'),
                ParamDefinition::optional('types', ['none', 'picture', 'high', 'other'], flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('user_id', -1),
                ParamDefinition::optional('image_id', null, WsParamType::ID),
                ParamDefinition::optional('filename'),
                ParamDefinition::optional('ip'),
                ParamDefinition::optional('display_thumbnail', 'display_thumbnail_classic'),
                ParamDefinition::optional('pageNumber', null, WsParamType::INT | WsParamType::POSITIVE),
            ],
            requiresAuth: true,
        ));
    }
}
