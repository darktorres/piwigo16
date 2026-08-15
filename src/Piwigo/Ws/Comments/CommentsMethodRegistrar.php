<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Comments;

use Piwigo\Config\CurrentConfig;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.comments*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class CommentsMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.userComments.getList',
            handlerClass: GetListHandler::class,
            description: 'Get comments',
            params: [
                ParamDefinition::optional('status', 'all', info: 'must be: all, validated or pending'),
                ParamDefinition::optional('search', info: 'All other parameters are not used during a search.'),
                ParamDefinition::optionalFlag('author_id', WsParamType::ID),
                ParamDefinition::optionalFlag('image_id', WsParamType::ID),
                ParamDefinition::optional('f_min_date'),
                ParamDefinition::optional('f_max_date'),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('per_page', $this->currentConfig->commentsPageNbComments, WsParamType::INT | WsParamType::POSITIVE),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.userComments.delete',
            handlerClass: DeleteHandler::class,
            description: 'Delete comments',
            params: [
                ParamDefinition::required('comment_id', WsParamType::INT | WsParamType::POSITIVE, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.userComments.validate',
            handlerClass: ValidateHandler::class,
            description: 'Validate comments',
            params: [
                ParamDefinition::required('comment_id', WsParamType::INT | WsParamType::POSITIVE, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
