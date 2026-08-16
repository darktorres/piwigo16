<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Piwigo\Config\CurrentConfig;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\SharedImageFilterParams;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.categories*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class CategoriesMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private ImageStdParams $imageStdParams,
    ) {}

    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.getImages',
            handlerClass: GetImagesHandler::class,
            description: 'Returns elements for the corresponding categories.
    <br><b>cat_id</b> can be empty if <b>recursive</b> is true.
    <br><b>order</b> comma separated fields for sorting',
            params: [
                ParamDefinition::optional('cat_id', null, WsParamType::INT | WsParamType::POSITIVE, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('recursive', false, WsParamType::BOOL),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...SharedImageFilterParams::get(),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.getList',
            handlerClass: GetListHandler::class,
            description: 'Returns a list of categories.',
            params: [
                ParamDefinition::optional('cat_id', null, WsParamType::INT | WsParamType::POSITIVE, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional('recursive', false, WsParamType::BOOL),
                ParamDefinition::optional('public', false, WsParamType::BOOL),
                ParamDefinition::optional('tree_output', false, WsParamType::BOOL),
                ParamDefinition::optional('fullname', false, WsParamType::BOOL),
                ParamDefinition::optional('thumbnail_size', ImageStdParams::THUMB, info: implode(',', array_keys($this->imageStdParams->getDefinedTypeMap()))),
                ParamDefinition::optional('search'),
                ParamDefinition::optional('limit', null, WsParamType::INT | WsParamType::POSITIVE, info: 'Parameter not compatible with recursive=true'),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.calculateOrphans',
            handlerClass: CalculateOrphansHandler::class,
            description: 'Return the number of orphan photos if an album is deleted.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.getAdminList',
            handlerClass: GetAdminListHandler::class,
            description: 'Get albums list as displayed on admin page. <br>
          <b>additional_output</b> controls which data are returned, possible values are:<br>
          null, full_name_with_admin_links<br>',
            params: [
                ParamDefinition::optional('cat_id', null, WsParamType::INT | WsParamType::POSITIVE, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional('search'),
                ParamDefinition::optional('recursive', true, WsParamType::BOOL),
                ParamDefinition::optional('additional_output', null, info: 'Comma saparated list (see method description)'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.add',
            handlerClass: AddHandler::class,
            description: 'Adds an album.<br><br><b>pwg_token</b> required. HTML in name/comment is allowed only when the allow_html_descriptions config is enabled.',
            params: [
                ParamDefinition::required('name'),
                ParamDefinition::optional('parent', null, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('visible', true, WsParamType::BOOL),
                ParamDefinition::optional('status', null, info: 'public, private'),
                ParamDefinition::optional('commentable', true, WsParamType::BOOL),
                ParamDefinition::optional('position', null, info: 'first, last'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.delete',
            handlerClass: DeleteHandler::class,
            description: 'Deletes album(s).
    <br><b>photo_deletion_mode</b> can be "no_delete" (may create orphan photos), "delete_orphans"
    (default mode, only deletes photos linked to no other album) or "force_delete" (delete all photos, even those linked to other albums)',
            params: [
                ParamDefinition::required('category_id', flags: WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::optional('photo_deletion_mode', 'delete_orphans'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.move',
            handlerClass: MoveHandler::class,
            description: 'Move album(s).
    <br>Set parent as 0 to move to gallery root. Only virtual categories can be moved.',
            params: [
                ParamDefinition::required('category_id', flags: WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::required('parent', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.setRepresentative',
            handlerClass: SetRepresentativeHandler::class,
            description: 'Sets the representative photo for an album. The photo doesn\'t have to belong to the album.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
                ParamDefinition::required('image_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.deleteRepresentative',
            handlerClass: DeleteRepresentativeHandler::class,
            description: 'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.refreshRepresentative',
            handlerClass: RefreshRepresentativeHandler::class,
            description: 'Find a new album thumbnail.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.setInfo',
            handlerClass: SetInfoHandler::class,
            description: 'Changes properties of an album.<br><br><b>pwg_token</b> required. HTML in name/comment is allowed only when the allow_html_descriptions config is enabled.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
                ParamDefinition::optionalFlag('name'),
                ParamDefinition::optionalFlag('comment'),
                ParamDefinition::optionalFlag('status', info: 'public, private'),
                ParamDefinition::optionalFlag('visible'),
                ParamDefinition::optionalFlag('commentable', info: 'Boolean, effective if configuration variable activate_comments is set to true'),
                ParamDefinition::optionalFlag('apply_commentable_to_subalbums', info: 'If true, set commentable to all sub album'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.categories.setRank',
            handlerClass: SetRankHandler::class,
            description: 'Changes the rank of an album
            <br><br>If you provide a list for category_id:
            <ul>
            <li>rank becomes useless, only the order of the image_id list matters</li>
            <li>you are supposed to provide the list of all categories_ids belonging to the album.
            </ul>.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('rank', WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
            ],
            requiresAuth: true,
            postOnly: true,
        ));
    }
}
