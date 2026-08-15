<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsParamFlag;
use Piwigo\Core\WsParamType;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\SharedImageFilterParams;

/**
 * Registers every `pwg.tags*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class TagsMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    public function register(Server $service): void
    {
        $service->register(new MethodDefinition(
            name: 'pwg.tags.getList',
            handlerClass: GetListHandler::class,
            description: 'Retrieves a list of available tags.',
            params: [
                ParamDefinition::optional('sort_by_counter', false, WsParamType::BOOL),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.getImages',
            handlerClass: GetImagesHandler::class,
            description: 'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.',
            params: [
                ParamDefinition::optional('tag_id', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_url_name', null, flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_name', null, flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_mode_and', false, WsParamType::BOOL),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...SharedImageFilterParams::get(),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.getAdminList',
            handlerClass: GetAdminListHandler::class,
            description: '<b>Admin only.</b>',
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.add',
            handlerClass: AddHandler::class,
            description: 'Adds a new tag.',
            params: [
                ParamDefinition::required('name'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.delete',
            handlerClass: DeleteHandler::class,
            description: 'Delete tag(s) by ID.',
            params: [
                ParamDefinition::required('tag_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.rename',
            handlerClass: RenameHandler::class,
            description: 'Rename tag',
            params: [
                ParamDefinition::required('tag_id', WsParamType::ID),
                ParamDefinition::required('new_name'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.duplicate',
            handlerClass: DuplicateHandler::class,
            description: 'Create a copy of a tag',
            params: [
                ParamDefinition::required('tag_id', WsParamType::ID),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.merge',
            handlerClass: MergeHandler::class,
            description: 'Merge tags in one other group',
            params: [
                ParamDefinition::required('destination_tag_id', WsParamType::ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required('merge_tag_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
