<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Config\Config;
use Piwigo\Event\Ws\WsMethodsRegistering;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\Method\CommentsEndpoints;
use Piwigo\Ws\Method\ExtensionsEndpoints;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\Method\GroupsEndpoints;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\Method\PermissionsEndpoints;
use Piwigo\Ws\Method\TagsEndpoints;
use Piwigo\Ws\Method\UsersEndpoints;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Core WS-method roster.
 *
 * Implements [[EventSubscriberInterface]] so [[PwgServer::populateMethods]]
 * only needs to dispatch [[WsMethodsRegistering]] — this class subscribes
 * with priority 100 so core registrations precede any plugin subscriber
 * (a plugin priority above 100 still wins, by design).
 *
 * The 1413-LOC body still lives inline; B15/B17 will continue the
 * decomposition by sharding the registration across the per-domain
 * endpoint classes via `#[ApiMethod]` decoration.
 */
final readonly class WsMethodRegistrar implements EventSubscriberInterface
{
    public function __construct(
        private CategoriesEndpoints $categoriesEndpoints,
        private CommentsEndpoints $commentsEndpoints,
        private ExtensionsEndpoints $extensionsEndpoints,
        private GeneralEndpoints $generalEndpoints,
        private GroupsEndpoints $groupsEndpoints,
        private ImagesEndpoints $imagesEndpoints,
        private PermissionsEndpoints $permissionsEndpoints,
        private TagsEndpoints $tagsEndpoints,
        private UsersEndpoints $usersEndpoints,
        private PermissionService $permissionService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [WsMethodsRegistering::class => ['onMethodsRegistering', 100]];
    }

    public function onMethodsRegistering(WsMethodsRegistering $event): void
    {
        $server = $event->server;
        $user = CurrentUser::get()->rawAttributes;
        $filterParams = [
            ParamDefinition::optional(name: 'f_min_rate', type: WsType::Float->value),
            ParamDefinition::optional(name: 'f_max_rate', type: WsType::Float->value),
            ParamDefinition::optional(name: 'f_min_hit', type: WsType::Int->value | WsType::Positive->value),
            ParamDefinition::optional(name: 'f_max_hit', type: WsType::Int->value | WsType::Positive->value),
            ParamDefinition::optional(name: 'f_min_ratio', type: WsType::Float->value | WsType::Positive->value),
            ParamDefinition::optional(name: 'f_max_ratio', type: WsType::Float->value | WsType::Positive->value),
            ParamDefinition::optional(name: 'f_max_level', type: WsType::Int->value | WsType::Positive->value),
            ParamDefinition::optional('f_min_date_available'),
            ParamDefinition::optional('f_max_date_available'),
            ParamDefinition::optional('f_min_date_created'),
            ParamDefinition::optional('f_max_date_created'),
        ];

        $server->register(new MethodDefinition(
            name:         'pwg.getVersion',
            handlerClass: \Piwigo\Ws\Action\Pwg\GetVersionHandler::class,
            description:  'Returns the Piwigo version.',
            tags:         ['pwg'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getInfos',
            handlerClass: \Piwigo\Ws\Action\Pwg\GetInfosHandler::class,
            description:  'Returns general informations.',
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getCacheSize',
            handlerClass: \Piwigo\Ws\Action\Pwg\GetCacheSizeHandler::class,
            description:  'Returns general informations.',
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.activity.getList',
            callback:     $this->generalEndpoints->getActivityList(...),
            description:  'Returns general informations.',
            params:       [
                ParamDefinition::optional(name: 'page', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'offset', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'uid', type: WsType::Id->value),
                ParamDefinition::optional('date_min'),
                ParamDefinition::optional('date_max'),
                ParamDefinition::optional(name: 'id', type: WsType::Id->value),
                ParamDefinition::optional('object'),
                ParamDefinition::optional('action'),
            ],
            tags:         ['activity'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.activity.downloadLog',
            callback:     static fn (mixed $p, PwgServer &$s): PwgError => new PwgError(WsError::InvalidMethod->value, 'Not implemented'),
            description:  'Returns general informations.',
            tags:         ['activity'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.caddie.add',
            handlerClass: \Piwigo\Ws\Action\Pwg\CaddieAddHandler::class,
            description:  'Adds elements to the caddie. Returns the number of elements added.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
            ],
            tags:         ['caddie'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.categories.getImages',
            callback:    $this->categoriesEndpoints->getImages(...),
            description: 'Returns elements for the corresponding categories.
<br><b>cat_id</b> can be empty if <b>recursive</b> is true.
<br><b>order</b> comma separated fields for sorting',
            params:      [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WsType::Int->value | WsType::Positive->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'recursive', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['categories'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.categories.getList',
            callback:    $this->categoriesEndpoints->getList(...),
            description: 'Returns a list of categories.',
            params:      [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WsType::Int->value | WsType::Positive->value, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional(name: 'recursive', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'public', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'tree_output', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'fullname', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'thumbnail_size', default: DerivativeSize::Thumb->value, info: implode(',', array_keys(ImageStdParams::getDefinedTypeMap()))),
                ParamDefinition::optional('search'),
                ParamDefinition::optional(name: 'limit', default: null, type: WsType::Int->value | WsType::Positive->value, info: 'Parameter not compatible with recursive=true'),
            ],
            tags:        ['categories'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getMissingDerivatives',
            handlerClass: \Piwigo\Ws\Action\Pwg\GetMissingDerivativesHandler::class,
            description:  'Returns a list of derivatives to build.',
            params:       [
                ParamDefinition::optional(name: 'types', default: null, flags: WsParam::ForceArray->value, info: 'square, thumb, 2small, xsmall, small, medium, large, xlarge, xxlarge, 3xlarge, 4xlarge'),
                ParamDefinition::optional(name: 'ids', default: null, type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'max_urls', default: 200, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'prev_page', default: null, type: WsType::Int->value | WsType::Positive->value),
                ...$filterParams,
            ],
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.addComment',
            callback:    $this->imagesEndpoints->addComment(...),
            description: 'Adds a comment to an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'author', default: $this->permissionService->isAGuest() ? 'guest' : $user['username']),
                ParamDefinition::required('content'),
                ParamDefinition::required('key'),
            ],
            tags:        ['images'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.getInfo',
            callback:    $this->imagesEndpoints->getInfo(...),
            description: 'Returns information about an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'comments_page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'comments_per_page', default: Config::nbCommentPage(), type: WsType::Int->value | WsType::Positive->value, maxValue: 2 * Config::nbCommentPage()),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.rate',
            callback:    $this->imagesEndpoints->rate(...),
            description: 'Rates an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::required(name: 'rate', type: WsType::Float->value),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.search',
            callback:    $this->imagesEndpoints->search(...),
            description: 'Returns elements for the corresponding query search.',
            params:      [
                ParamDefinition::required('query'),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setPrivacyLevel',
            callback:     $this->imagesEndpoints->setPrivacyLevel(...),
            description:  'Sets the privacy levels for the images.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required(name: 'level', type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.formats.searchImage',
            callback:     $this->imagesEndpoints->formatsSearchImage(...),
            description:  'Search for image ids matching the provided filenames. <b>filename_list</b> must be a JSON encoded associative array of unique_id:filename.<br><br>The method returns a list of unique_id:image_id.',
            params:       [
                ParamDefinition::required('filename_list'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.formats.delete',
            callback:     $this->imagesEndpoints->formatsDelete(...),
            description:  'Remove a format',
            params:       [
                ParamDefinition::optional(name: 'format_id', default: null, type: WsType::Id->value, flags: WsParam::AcceptArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setRank',
            callback:     $this->imagesEndpoints->setRank(...),
            description:  'Sets the rank of a photo for a given album.
<br><br>If you provide a list for image_id:
<ul>
<li>rank becomes useless, only the order of the image_id list matters</li>
<li>you are supposed to provide the list of all image_ids belonging to the album.
</ul>',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'rank', default: null, type: WsType::Int->value | WsType::Positive->value | WsType::NotNull->value),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setCategory',
            callback:     $this->imagesEndpoints->setCategory(...),
            description:  'Manage associations of images with an album. <b>action</b> can be:<ul><li><i>associate</i> : add photos to this album</li><li><i>dissociate</i> : remove photos from this album</li><li><i>move</i> : dissociate photos from any other album and adds photos to this album</li></ul>',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'action', default: 'associate', info: 'associate/dissociate/move'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.rates.delete',
            callback:     $this->generalEndpoints->ratesDelete(...),
            description:  'Deletes all rates for a user.',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value),
                ParamDefinition::optional('anonymous_id'),
                ParamDefinition::optionalFlag(name: 'image_id', type: WsType::Id->value),
            ],
            tags:         ['rates'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.session.getStatus',
            handlerClass: \Piwigo\Ws\Action\Pwg\Session\GetStatusHandler::class,
            description:  'Gets information about the current session. Also provides a token useable with admin methods.',
            tags:         ['session'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.session.login',
            handlerClass: \Piwigo\Ws\Action\Pwg\Session\LoginHandler::class,
            description:  'Tries to login the user.',
            params:       [
                ParamDefinition::required('username'),
                ParamDefinition::optional('password'),
            ],
            tags:         ['session'],
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.session.logout',
            handlerClass: \Piwigo\Ws\Action\Pwg\Session\LogoutHandler::class,
            description:  'Ends the current session.',
            tags:         ['session'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.tags.getList',
            callback:    $this->tagsEndpoints->getList(...),
            description: 'Retrieves a list of available tags.',
            params:      [
                ParamDefinition::optional(name: 'sort_by_counter', default: false, type: WsType::Bool->value),
            ],
            tags:        ['tags'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.tags.getImages',
            callback:    $this->tagsEndpoints->getImages(...),
            description: 'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.',
            params:      [
                ParamDefinition::optional(name: 'tag_id', default: null, type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'tag_url_name', default: null, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'tag_name', default: null, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'tag_mode_and', default: false, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['tags'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addChunk',
            callback:     $this->imagesEndpoints->addChunk(...),
            description:  'Add a chunk of a file.',
            params:       [
                ParamDefinition::required('data'),
                ParamDefinition::required('original_sum'),
                ParamDefinition::required('position'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addFile',
            callback:     $this->imagesEndpoints->addFile(...),
            description:  'Add or update a file for an existing photo.
<br>pwg.images.addChunk must have been called before (maybe several times).',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::required('sum'),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.add',
            callback:     $this->imagesEndpoints->add(...),
            description:  'Add an image.
<br>pwg.images.addChunk must have been called before (maybe several times).',
            params:       [
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional('original_filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'categories', default: null, info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'level', default: 0, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'check_uniqueness', default: true, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'image_id', default: null, type: WsType::Id->value),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addSimple',
            callback:     $this->imagesEndpoints->addSimple(...),
            description:  'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.',
            params:       [
                ParamDefinition::optional(name: 'category', default: null, type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'level', default: 0, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'tags', default: null, flags: WsParam::AcceptArray->value),
                ParamDefinition::optional(name: 'image_id', default: null, type: WsType::Id->value),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.upload',
            callback:     $this->imagesEndpoints->upload(...),
            description:  'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".',
            params:       [
                ParamDefinition::optional('name'),
                ParamDefinition::optional(name: 'category', default: null, type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'level', default: 0, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'format_of', default: null, type: WsType::Id->value, info: 'id of the extended image (name/category/level are not used if format_of is provided)'),
                ParamDefinition::optional(name: 'update_mode', default: false, type: WsType::Bool->value, info: 'true if the update mode is active'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.uploadAsync',
            callback:     $this->imagesEndpoints->uploadAsync(...),
            description:  'Upload photo by chunks in a random order.
<br>Use the <b>$_FILES[file]</b> field for uploading file.
<br>Start with chunk 0 (zero).
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.
<br>Requires <b>admin</b> credentials: either with username/password or header authorization with api key.',
            params:       [
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optional('password'),
                ParamDefinition::required(name: 'chunk', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::required('chunk_sum'),
                ParamDefinition::required(name: 'chunks', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional(name: 'category', default: null, type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional(name: 'level', default: 0, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'image_id', default: null, type: WsType::Id->value),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.delete',
            callback:     $this->imagesEndpoints->delete(...),
            description:  'Deletes image(s).',
            params:       [
                ParamDefinition::required(name: 'image_id', flags: WsParam::AcceptArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setMd5sum',
            callback:     $this->imagesEndpoints->setMd5sum(...),
            description:  'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.',
            params:       [
                ParamDefinition::optional(name: 'block_size', default: Config::checksumComputeBlocksize(), type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.syncMetadata',
            callback:     $this->imagesEndpoints->syncMetadata(...),
            description:  'Sync metadatas, by blocks. Returns how many images were synchronized',
            params:       [
                ParamDefinition::required(name: 'image_id', flags: WsParam::AcceptArray->value, info: 'Comma separated ids or array of id'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.deleteOrphans',
            callback:     $this->imagesEndpoints->deleteOrphans(...),
            description:  'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.',
            params:       [
                ParamDefinition::optional(name: 'block_size', default: 1000, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.calculateOrphans',
            callback:     $this->categoriesEndpoints->calculateOrphans(...),
            description:  'Return the number of orphan photos if an album is deleted.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.getAdminList',
            callback:     $this->categoriesEndpoints->getAdminList(...),
            description:  'Get albums list as displayed on admin page. <br>
      <b>additional_output</b> controls which data are returned, possible values are:<br>
      null, full_name_with_admin_links<br>',
            params:       [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WsType::Int->value | WsType::Positive->value, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional('search'),
                ParamDefinition::optional(name: 'recursive', default: true, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'additional_output', default: null, info: 'Comma saparated list (see method description)'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.add',
            callback:     $this->categoriesEndpoints->add(...),
            description:  'Adds an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            params:       [
                ParamDefinition::required('name'),
                ParamDefinition::optional(name: 'parent', default: null, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'visible', default: true, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'status', default: null, info: 'public, private'),
                ParamDefinition::optional(name: 'commentable', default: true, type: WsType::Bool->value),
                ParamDefinition::optional(name: 'position', default: null, info: 'first, last'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.delete',
            callback:     $this->categoriesEndpoints->delete(...),
            description:  'Deletes album(s).
<br><b>photo_deletion_mode</b> can be "no_delete" (may create orphan photos), "delete_orphans"
(default mode, only deletes photos linked to no other album) or "force_delete" (delete all photos, even those linked to other albums)',
            params:       [
                ParamDefinition::required(name: 'category_id', flags: WsParam::AcceptArray->value),
                ParamDefinition::optional(name: 'photo_deletion_mode', default: 'delete_orphans'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.move',
            callback:     $this->categoriesEndpoints->move(...),
            description:  'Move album(s).
<br>Set parent as 0 to move to gallery root. Only virtual categories can be moved.',
            params:       [
                ParamDefinition::required(name: 'category_id', flags: WsParam::AcceptArray->value),
                ParamDefinition::required(name: 'parent', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.setRepresentative',
            callback:     $this->categoriesEndpoints->setRepresentative(...),
            description:  'Sets the representative photo for an album. The photo doesn\'t have to belong to the album.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.deleteRepresentative',
            callback:     $this->categoriesEndpoints->deleteRepresentative(...),
            description:  'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.refreshRepresentative',
            callback:     $this->categoriesEndpoints->refreshRepresentative(...),
            description:  'Find a new album thumbnail.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.getAdminList',
            callback:     $this->tagsEndpoints->getAdminList(...),
            description:  '<b>Admin only.</b>',
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.add',
            callback:     $this->tagsEndpoints->add(...),
            description:  'Adds a new tag.',
            params:       [
                ParamDefinition::required('name'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.delete',
            callback:     $this->tagsEndpoints->delete(...),
            description:  'Delete tag(s) by ID.',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.rename',
            callback:     $this->tagsEndpoints->rename(...),
            description:  'Rename tag',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WsType::Id->value),
                ParamDefinition::required('new_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.duplicate',
            callback:     $this->tagsEndpoints->duplicate(...),
            description:  'Create a copy of a tag',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WsType::Id->value),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.merge',
            callback:     $this->tagsEndpoints->merge(...),
            description:  'Merge tags in one other group',
            params:       [
                ParamDefinition::required(name: 'destination_tag_id', type: WsType::Id->value, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required(name: 'merge_tag_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.exist',
            callback:     $this->imagesEndpoints->exist(...),
            description:  'Checks existence of images.
<br>Give <b>md5sum_list</b> if $conf[uniqueness_mode]==md5sum. Give <b>filename_list</b> if $conf[uniqueness_mode]==filename.',
            params:       [
                ParamDefinition::optional('md5sum_list'),
                ParamDefinition::optional('filename_list'),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.checkFiles',
            callback:     $this->imagesEndpoints->checkFiles(...),
            description:  'Checks if you have updated version of your files for a given photo, the answer can be "missing", "equals" or "differs".',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional('file_sum'),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.checkUpload',
            callback:     $this->imagesEndpoints->checkUpload(...),
            description:  'Checks if Piwigo is ready for upload.',
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.emptyLounge',
            callback:     $this->imagesEndpoints->emptyLounge(...),
            description:  'Empty lounge, where images may be waiting before taking off.',
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.uploadCompleted',
            callback:     $this->imagesEndpoints->uploadCompleted(...),
            description:  'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.',
            params:       [
                ParamDefinition::optional(name: 'image_id', default: null, flags: WsParam::AcceptArray->value),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setInfo',
            callback:     $this->imagesEndpoints->setInfo(...),
            description:  'Changes properties of an image.
<br><b>single_value_mode</b> can be "fill_if_empty" (only use the input value if the corresponding values is currently empty) or "replace"
(overwrite any existing value) and applies to single values properties like name/author/date_creation/comment.
<br><b>multiple_value_mode</b> can be "append" (no change on existing values, add the new values) or "replace" and applies to multiple values properties like tag_ids/categories.
<br><b>pwg_token</b> required if you want to use HTML in name/comment/author.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional('file'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'categories', default: null, info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'level', default: null, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'single_value_mode', default: 'fill_if_empty'),
                ParamDefinition::optional(name: 'multiple_value_mode', default: 'append'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.setInfo',
            callback:     $this->categoriesEndpoints->setInfo(...),
            description:  'Changes properties of an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'name', default: null, info: ''),
                ParamDefinition::optional(name: 'comment', default: null),
                ParamDefinition::optional(name: 'status', default: null, info: 'public, private'),
                ParamDefinition::optional(name: 'visible', default: null),
                ParamDefinition::optional(name: 'commentable', default: null, info: 'Boolean, effective if configuration variable activate_comments is set to true'),
                ParamDefinition::optional(name: 'apply_commentable_to_subalbums', default: null, info: 'If true, set commentable to all sub album'),
                ParamDefinition::optional('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.setRank',
            callback:     $this->categoriesEndpoints->setRank(...),
            description:  'Changes the rank of an album
        <br><br>If you provide a list for category_id:
        <ul>
        <li>rank becomes useless, only the order of the image_id list matters</li>
        <li>you are supposed to provide the list of all categories_ids belonging to the album.
        </ul>.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'rank', type: WsType::Int->value | WsType::Positive->value | WsType::NotNull->value),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.plugins.getList',
            callback:     $this->extensionsEndpoints->pluginsGetList(...),
            description:  'Gets the list of plugins with id, name, version, state and description.',
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.plugins.performAction',
            callback:     $this->extensionsEndpoints->pluginsPerformAction(...),
            params:       [
                ParamDefinition::required(name: 'action', info: 'install, activate, deactivate, uninstall, delete'),
                ParamDefinition::required('plugin'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.themes.performAction',
            callback:     $this->extensionsEndpoints->themesPerformAction(...),
            params:       [
                ParamDefinition::required(name: 'action', info: 'activate, deactivate, delete, set_default'),
                ParamDefinition::required('theme'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.extensions.update',
            callback:     $this->extensionsEndpoints->update(...),
            description:  '<b>Webmaster only.</b>',
            params:       [
                ParamDefinition::required(name: 'type', info: 'plugins, languages, themes'),
                ParamDefinition::required('id'),
                ParamDefinition::required('revision'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.extensions.ignoreUpdate',
            callback:     $this->extensionsEndpoints->ignoreUpdate(...),
            description:  '<b>Webmaster only.</b> Ignores an extension if it needs update.',
            params:       [
                ParamDefinition::optional(name: 'type', default: null, info: 'plugins, languages, themes'),
                ParamDefinition::optional('id'),
                ParamDefinition::optional(name: 'reset', default: false, type: WsType::Bool->value, info: 'If true, all ignored extensions will be reinitilized.'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.extensions.checkUpdates',
            callback:     $this->extensionsEndpoints->checkUpdates(...),
            description:  'Checks if piwigo or extensions are up to date.',
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.getList',
            callback:     $this->groupsEndpoints->getList(...),
            description:  'Retrieves a list of all groups. The list can be filtered.',
            params:       [
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'name', info: 'Use "%" as wildcard.'),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxUsersPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: 'name', info: 'id, name, nb_users, is_default'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.add',
            callback:     $this->groupsEndpoints->add(...),
            description:  'Creates a group and returns the new group record.',
            params:       [
                ParamDefinition::required('name'),
                ParamDefinition::optional(name: 'is_default', default: false, type: WsType::Bool->value),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.delete',
            callback:     $this->groupsEndpoints->delete(...),
            description:  'Deletes a or more groups. Users and photos are not deleted.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.setInfo',
            callback:     $this->groupsEndpoints->setInfo(...),
            description:  'Updates a group. Leave a field blank to keep the current value.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WsType::Id->value),
                ParamDefinition::optionalFlag('name'),
                ParamDefinition::optionalFlag(name: 'is_default', type: WsType::Bool->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.addUser',
            callback:     $this->groupsEndpoints->addUser(...),
            description:  'Adds one or more users to a group.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WsType::Id->value),
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.deleteUser',
            callback:     $this->groupsEndpoints->deleteUser(...),
            description:  'Removes one or more users from a group.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WsType::Id->value),
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.merge',
            callback:     $this->groupsEndpoints->merge(...),
            description:  'Merge groups in one other group',
            params:       [
                ParamDefinition::required(name: 'destination_group_id', type: WsType::Id->value, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required(name: 'merge_group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.duplicate',
            callback:     $this->groupsEndpoints->duplicate(...),
            description:  'Create a copy of a group',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WsType::Id->value),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.getList',
            callback:     $this->usersEndpoints->getList(...),
            description:  'Retrieves a list of all the users.<br>
<br>
<b>display</b> controls which data are returned, possible values are:<br>
all, basics, none,<br>
username, email, status, level, groups,<br>
language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits,<br>
enabled_high, registration_date, registration_date_string, registration_date_since, last_visit, last_visit_string, last_visit_since<br>
<b>basics</b> stands for "username,email,status,level,groups"<br>
<b>min_register</b> and <b>max_register</b> filter users by their registration date expecting format "YYYY" or "YYYY-mm" or "YYYY-mm-dd".',
            params:       [
                ParamDefinition::optionalFlag(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'username', info: 'Use "%" as wildcard.'),
                ParamDefinition::optionalFlag(name: 'status', flags: WsParam::ForceArray->value, info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optional(name: 'min_level', default: 0, type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxUsersPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: 'id', info: 'id, username, level, email'),
                ParamDefinition::optionalFlag(name: 'exclude', type: WsType::Id->value, flags: WsParam::ForceArray->value, info: 'Expects a user_id as value.'),
                ParamDefinition::optional(name: 'display', default: 'basics', info: 'Comma saparated list (see method description)'),
                ParamDefinition::optionalFlag(name: 'filter', info: 'Filter by username, email, group'),
                ParamDefinition::optionalFlag(name: 'min_register', info: 'See method description'),
                ParamDefinition::optionalFlag(name: 'max_register', info: 'See method description'),
            ],
            tags:         ['users'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.add',
            callback:     $this->usersEndpoints->add(...),
            description:  'Registers a new user.',
            params:       [
                ParamDefinition::required('username'),
                ParamDefinition::optional(name: 'auto_password', default: false, info: 'if true ignores password and confirm password'),
                ParamDefinition::optional('password'),
                ParamDefinition::optionalFlag('password_confirm'),
                ParamDefinition::optional('email'),
                ParamDefinition::optional(name: 'send_password_by_mail', default: false, type: WsType::Bool->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.delete',
            callback:     $this->usersEndpoints->delete(...),
            description:  'Deletes on or more users. Photos owned by this user are not deleted.',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.getAuthKey',
            callback:     $this->usersEndpoints->getAuthKey(...),
            description:  'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.setInfo',
            callback:     $this->usersEndpoints->setInfo(...),
            description:  'Updates a user. Leave a field blank to keep the current value.
<br>"username", "password" and "email" are ignored if "user_id" is an array.
<br>set "group_id" to -1 if you want to dissociate users from all groups',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag(name: 'status', info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optionalFlag(name: 'level', type: WsType::Int->value | WsType::Positive->value, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Int->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'nb_image_page', type: WsType::Int->value | WsType::Positive->value | WsType::NotNull->value),
                ParamDefinition::optionalFlag(name: 'recent_period', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'expand', type: WsType::Bool->value),
                ParamDefinition::optionalFlag(name: 'show_nb_comments', type: WsType::Bool->value),
                ParamDefinition::optionalFlag(name: 'show_nb_hits', type: WsType::Bool->value),
                ParamDefinition::optionalFlag(name: 'enabled_high', type: WsType::Bool->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.setMyInfo',
            callback:    $this->usersEndpoints->setMyInfo(...),
            params:      [
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag(name: 'nb_image_page', type: WsType::Int->value | WsType::Positive->value | WsType::NotNull->value),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag(name: 'recent_period', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'expand', type: WsType::Bool->value),
                ParamDefinition::optionalFlag(name: 'show_nb_comments', type: WsType::Bool->value),
                ParamDefinition::optionalFlag(name: 'show_nb_hits', type: WsType::Bool->value),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('new_password'),
                ParamDefinition::optionalFlag('conf_new_password'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.permissions.getList',
            callback:     $this->permissionsEndpoints->getList(...),
            description:  'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
<br>Provide only one parameter!',
            params:       [
                ParamDefinition::optionalFlag(name: 'cat_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.permissions.add',
            callback:     $this->permissionsEndpoints->add(...),
            description:  'Adds permissions to an album.',
            params:       [
                ParamDefinition::required(name: 'cat_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'recursive', default: false, type: WsType::Bool->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.permissions.remove',
            callback:     $this->permissionsEndpoints->remove(...),
            description:  'Removes permissions from an album.',
            params:       [
                ParamDefinition::required(name: 'cat_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'user_id', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.preferences.set',
            callback:    $this->usersEndpoints->preferencesSet(...),
            description: 'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.',
            params:      [
                ParamDefinition::required('param'),
                ParamDefinition::optionalFlag('value'),
                ParamDefinition::optional(name: 'is_json', default: false, type: WsType::Bool->value),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.add',
            callback:    $this->usersEndpoints->favoritesAdd(...),
            description: 'Adds the indicated image to the current user\'s favorite images.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.remove',
            callback:    $this->usersEndpoints->favoritesRemove(...),
            description: 'Removes the indicated image from the current user\'s favorite images.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.getList',
            callback:    $this->usersEndpoints->favoritesGetList(...),
            description: 'Returns the favorite images of the current user.',
            params:      [
                ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.history.log',
            callback:    $this->generalEndpoints->historyLog(...),
            description: 'Log visit in history',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional(name: 'cat_id', default: null, type: WsType::Id->value),
                ParamDefinition::optional('section'),
                ParamDefinition::optional('tags_string'),
                ParamDefinition::optional(name: 'is_download', default: false, type: WsType::Bool->value),
            ],
            tags:        ['history'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.history.search',
            callback:     $this->generalEndpoints->historySearch(...),
            description:  'Gives an history of who has visited the galery and the actions done in it. Receives parameter.
      <br> <strong>Types </strong> can be : \'none\', \'picture\', \'high\', \'other\'
      <br> <strong>Date format</strong> is yyyy-mm-dd
      <br> <strong>display_thumbnail</strong> can be : \'no_display_thumbnail\', \'display_thumbnail_classic\', \'display_thumbnail_hoverbox\'',
            params:       [
                ParamDefinition::optional('start'),
                ParamDefinition::optional('end'),
                ParamDefinition::optional(name: 'types', default: ['none', 'picture', 'high', 'other'], flags: WsParam::ForceArray->value),
                ParamDefinition::optional(name: 'user_id', default: -1),
                ParamDefinition::optional(name: 'image_id', default: null, type: WsType::Id->value),
                ParamDefinition::optional('filename'),
                ParamDefinition::optional('ip'),
                ParamDefinition::optional(name: 'display_thumbnail', default: 'display_thumbnail_classic'),
                ParamDefinition::optional(name: 'pageNumber', default: null, type: WsType::Int->value | WsType::Positive->value),
            ],
            tags:         ['history'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.filteredSearch.create',
            callback:    $this->imagesEndpoints->filteredSearchCreate(...),
            params:      [
                ParamDefinition::optionalFlag(name: 'search_id', info: 'prior search_id (or search_key), if any'),
                ParamDefinition::optionalFlag(name: 'allwords', info: 'query to search by words'),
                ParamDefinition::optionalFlag(name: 'allwords_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag(name: 'allwords_fields', flags: WsParam::ForceArray->value, info: 'values among [name, comment, tags, file, author, cat-title, cat-desc]'),
                ParamDefinition::optionalFlag(name: 'tags', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'tags_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag(name: 'categories', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'categories_withsubs', type: WsType::Bool->value, info: 'false, by default'),
                ParamDefinition::optionalFlag(name: 'authors', flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'added_by', type: WsType::Id->value, flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'filetypes', flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'date_posted_preset', info: 'files posted within 24 hours, 7 days, 30 days, 3 months, 6 months or custom. Value among 24h|7d|30d|3m|6m|custom.'),
                ParamDefinition::optionalFlag(name: 'date_posted_custom', flags: WsParam::ForceArray->value, info: 'Must be provided if date_posted_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag(name: 'date_created_preset', info: 'files created within 7 days, 30 days, 3 months, 6 months, 12 months or custom. Value among 7d|30d|3m|6m|12m|custom.'),
                ParamDefinition::optionalFlag(name: 'date_created_custom', flags: WsParam::ForceArray->value, info: 'Must be provided if date_created_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag(name: 'ratios', flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'ratings', flags: WsParam::ForceArray->value),
                ParamDefinition::optionalFlag(name: 'filesize_min', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'filesize_max', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'height_min', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'height_max', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'width_min', type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optionalFlag(name: 'width_max', type: WsType::Int->value | WsType::Positive->value),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.generatePasswordLink',
            callback:     $this->usersEndpoints->generatePasswordLink(...),
            description:  'Return the reset password link <br />
       (Only webmaster can perform this action for another webmaster)',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::optional(name: 'send_by_mail', default: false, type: WsType::Bool->value),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.setMainUser',
            callback:     $this->usersEndpoints->setMainUser(...),
            description:  'Update the main user (owner) <br />
        - To be the main user, the user must have the status "webmaster".<br />
        - Only a webmaster can perform this action',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WsType::Id->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.create',
            callback:    $this->usersEndpoints->createApiKey(...),
            description: 'Create a new api key for the user in the current session',
            params:      [
                ParamDefinition::required('key_name'),
                ParamDefinition::required(name: 'duration', type: WsType::Int->value | WsType::Positive->value, info: 'Number of days'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.revoke',
            callback:    $this->usersEndpoints->revokeApiKey(...),
            description: 'Revoke a api key for the user in the current session',
            params:      [
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.edit',
            callback:    $this->usersEndpoints->editApiKey(...),
            description: 'Edit a api key for the user in the current session',
            params:      [
                ParamDefinition::required('key_name'),
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.get',
            callback:    $this->usersEndpoints->getApiKey(...),
            description: 'Get all api key for the user in the current session',
            params:      [
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.getList',
            callback:     $this->commentsEndpoints->getList(...),
            description:  'Get comments',
            params:       [
                ParamDefinition::optional(name: 'status', default: 'all', info: 'must be: all, validated or pending'),
                ParamDefinition::optional(name: 'search', default: null, info: 'All other parameters are not used during a search.'),
                ParamDefinition::optionalFlag(name: 'author_id', type: WsType::Id->value),
                ParamDefinition::optionalFlag(name: 'image_id', type: WsType::Id->value),
                ParamDefinition::optional('f_min_date'),
                ParamDefinition::optional('f_max_date'),
                ParamDefinition::optional(name: 'page', default: 0, type: WsType::Int->value | WsType::Positive->value),
                ParamDefinition::optional(name: 'per_page', default: Config::commentsPageNbComments(), type: WsType::Int->value | WsType::Positive->value),
            ],
            tags:         ['comments'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.delete',
            callback:     $this->commentsEndpoints->delete(...),
            description:  'Delete comments',
            params:       [
                ParamDefinition::required(name: 'comment_id', type: WsType::Int->value | WsType::Positive->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['comments'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.validate',
            callback:     $this->commentsEndpoints->validate(...),
            description:  'Validate comments',
            params:       [
                ParamDefinition::required(name: 'comment_id', type: WsType::Int->value | WsType::Positive->value, flags: WsParam::ForceArray->value),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['comments'],
            requiresAuth: true,
            postOnly:     true,
        ));
    }
}
