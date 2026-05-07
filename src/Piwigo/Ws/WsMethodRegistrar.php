<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\Method\CommentsEndpoints;
use Piwigo\Ws\Method\ExtensionsEndpoints;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\Method\GroupsEndpoints;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\Method\PermissionsEndpoints;
use Piwigo\Ws\Method\TagsEndpoints;
use Piwigo\Ws\Method\UsersEndpoints;
use Piwigo\Users\PermissionService;

final class WsMethodRegistrar
{
    public static function register(PwgServer $server): void
    {
        $user    = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $filterParams = [
            ParamDefinition::optional(name: 'f_min_rate', type: WS_TYPE_FLOAT),
            ParamDefinition::optional(name: 'f_max_rate', type: WS_TYPE_FLOAT),
            ParamDefinition::optional(name: 'f_min_hit', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ParamDefinition::optional(name: 'f_max_hit', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ParamDefinition::optional(name: 'f_min_ratio', type: WS_TYPE_FLOAT | WS_TYPE_POSITIVE),
            ParamDefinition::optional(name: 'f_max_ratio', type: WS_TYPE_FLOAT | WS_TYPE_POSITIVE),
            ParamDefinition::optional(name: 'f_max_level', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ParamDefinition::optional('f_min_date_available'),
            ParamDefinition::optional('f_max_date_available'),
            ParamDefinition::optional('f_min_date_created'),
            ParamDefinition::optional('f_max_date_created'),
        ];

        $server->register(new MethodDefinition(
            name:        'pwg.getVersion',
            callback:    ServiceLocator::get(GeneralEndpoints::class)->getVersion(...),
            description: 'Returns the Piwigo version.',
            tags:        ['pwg'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getInfos',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->getInfos(...),
            description:  'Returns general informations.',
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getCacheSize',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->getCacheSize(...),
            description:  'Returns general informations.',
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.activity.getList',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->getActivityList(...),
            description:  'Returns general informations.',
            params:       [
                ParamDefinition::optional(name: 'page', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'offset', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'uid', type: WS_TYPE_ID),
                ParamDefinition::optional('date_min'),
                ParamDefinition::optional('date_max'),
                ParamDefinition::optional(name: 'id', type: WS_TYPE_ID),
                ParamDefinition::optional('object'),
                ParamDefinition::optional('action'),
            ],
            tags:         ['activity'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.activity.downloadLog',
            callback:     static fn (mixed $p, PwgServer &$s): PwgError => new PwgError(WS_ERR_INVALID_METHOD, 'Not implemented'),
            description:  'Returns general informations.',
            tags:         ['activity'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.caddie.add',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->caddieAdd(...),
            description:  'Adds elements to the caddie. Returns the number of elements added.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
            ],
            tags:         ['caddie'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.categories.getImages',
            callback:    ServiceLocator::get(CategoriesEndpoints::class)->getImages(...),
            description: 'Returns elements for the corresponding categories.
<br><b>cat_id</b> can be empty if <b>recursive</b> is true.
<br><b>order</b> comma separated fields for sorting',
            params:      [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'recursive', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['categories'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.categories.getList',
            callback:    ServiceLocator::get(CategoriesEndpoints::class)->getList(...),
            description: 'Returns a list of categories.',
            params:      [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional(name: 'recursive', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'public', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'tree_output', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'fullname', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'thumbnail_size', default: IMG_THUMB, info: implode(',', array_keys(ImageStdParams::getDefinedTypeMap()))),
                ParamDefinition::optional('search'),
                ParamDefinition::optional(name: 'limit', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE, info: 'Parameter not compatible with recursive=true'),
            ],
            tags:        ['categories'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.getMissingDerivatives',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->getMissingDerivatives(...),
            description:  'Returns a list of derivatives to build.',
            params:       [
                ParamDefinition::optional(name: 'types', default: null, flags: WS_PARAM_FORCE_ARRAY, info: 'square, thumb, 2small, xsmall, small, medium, large, xlarge, xxlarge, 3xlarge, 4xlarge'),
                ParamDefinition::optional(name: 'ids', default: null, type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'max_urls', default: 200, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'prev_page', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ...$filterParams,
            ],
            tags:         ['pwg'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.addComment',
            callback:    ServiceLocator::get(ImagesEndpoints::class)->addComment(...),
            description: 'Adds a comment to an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'author', default: PermissionService::get()->isAGuest() ? 'guest' : $user['username']),
                ParamDefinition::required('content'),
                ParamDefinition::required('key'),
            ],
            tags:        ['images'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.getInfo',
            callback:    ServiceLocator::get(ImagesEndpoints::class)->getInfo(...),
            description: 'Returns information about an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'comments_page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'comments_per_page', default: Config::nbCommentPage(), type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: 2 * Config::nbCommentPage()),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.rate',
            callback:    ServiceLocator::get(ImagesEndpoints::class)->rate(...),
            description: 'Rates an image.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::required(name: 'rate', type: WS_TYPE_FLOAT),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.search',
            callback:    ServiceLocator::get(ImagesEndpoints::class)->search(...),
            description: 'Returns elements for the corresponding query search.',
            params:      [
                ParamDefinition::required('query'),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setPrivacyLevel',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->setPrivacyLevel(...),
            description:  'Sets the privacy levels for the images.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required(name: 'level', type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.formats.searchImage',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->formatsSearchImage(...),
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
            callback:     ServiceLocator::get(ImagesEndpoints::class)->formatsDelete(...),
            description:  'Remove a format',
            params:       [
                ParamDefinition::optional(name: 'format_id', default: null, type: WS_TYPE_ID, flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setRank',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->setRank(...),
            description:  'Sets the rank of a photo for a given album.
<br><br>If you provide a list for image_id:
<ul>
<li>rank becomes useless, only the order of the image_id list matters</li>
<li>you are supposed to provide the list of all image_ids belonging to the album.
</ul>',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'rank', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setCategory',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->setCategory(...),
            description:  'Manage associations of images with an album. <b>action</b> can be:<ul><li><i>associate</i> : add photos to this album</li><li><i>dissociate</i> : remove photos from this album</li><li><i>move</i> : dissociate photos from any other album and adds photos to this album</li></ul>',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'action', default: 'associate', info: 'associate/dissociate/move'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.rates.delete',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->ratesDelete(...),
            description:  'Deletes all rates for a user.',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID),
                ParamDefinition::optional('anonymous_id'),
                ParamDefinition::optionalFlag(name: 'image_id', type: WS_TYPE_ID),
            ],
            tags:         ['rates'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.session.getStatus',
            callback:    ServiceLocator::get(GeneralEndpoints::class)->sessionGetStatus(...),
            description: 'Gets information about the current session. Also provides a token useable with admin methods.',
            tags:        ['session'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.session.login',
            callback:    ServiceLocator::get(GeneralEndpoints::class)->sessionLogin(...),
            description: 'Tries to login the user.',
            params:      [
                ParamDefinition::required('username'),
                ParamDefinition::optional('password'),
            ],
            tags:        ['session'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.session.logout',
            callback:    ServiceLocator::get(GeneralEndpoints::class)->sessionLogout(...),
            description: 'Ends the current session.',
            tags:        ['session'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.tags.getList',
            callback:    ServiceLocator::get(TagsEndpoints::class)->getList(...),
            description: 'Retrieves a list of available tags.',
            params:      [
                ParamDefinition::optional(name: 'sort_by_counter', default: false, type: WS_TYPE_BOOL),
            ],
            tags:        ['tags'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.tags.getImages',
            callback:    ServiceLocator::get(TagsEndpoints::class)->getImages(...),
            description: 'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.',
            params:      [
                ParamDefinition::optional(name: 'tag_id', default: null, type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'tag_url_name', default: null, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'tag_name', default: null, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'tag_mode_and', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...$filterParams,
            ],
            tags:        ['tags'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addChunk',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->addChunk(...),
            description:  'Add a chunk of a file.',
            params:       [
                ParamDefinition::required('data'),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional(name: 'type', default: 'file', info: 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.'),
                ParamDefinition::required('position'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addFile',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->addFile(...),
            description:  'Add or update a file for an existing photo.
<br>pwg.images.addChunk must have been called before (maybe several times).',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'type', default: 'file', info: 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.'),
                ParamDefinition::required('sum'),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.add',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->add(...),
            description:  'Add an image.
<br>pwg.images.addChunk must have been called before (maybe several times).
<br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            params:       [
                ParamDefinition::optional('thumbnail_sum'),
                ParamDefinition::optional('high_sum'),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional('original_filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'categories', default: null, info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'level', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'check_uniqueness', default: true, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'image_id', default: null, type: WS_TYPE_ID),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.addSimple',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->addSimple(...),
            description:  'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.',
            params:       [
                ParamDefinition::optional(name: 'category', default: null, type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'level', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'tags', default: null, flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::optional(name: 'image_id', default: null, type: WS_TYPE_ID),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.upload',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->upload(...),
            description:  'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".',
            params:       [
                ParamDefinition::optional('name'),
                ParamDefinition::optional(name: 'category', default: null, type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'level', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'format_of', default: null, type: WS_TYPE_ID, info: 'id of the extended image (name/category/level are not used if format_of is provided)'),
                ParamDefinition::optional(name: 'update_mode', default: false, type: WS_TYPE_BOOL, info: 'true if the update mode is active'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.uploadAsync',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->uploadAsync(...),
            description:  'Upload photo by chunks in a random order.
<br>Use the <b>$_FILES[file]</b> field for uploading file.
<br>Start with chunk 0 (zero).
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.
<br>Requires <b>admin</b> credentials: either with username/password or header authorization with api key.',
            params:       [
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optional('password'),
                ParamDefinition::required(name: 'chunk', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::required('chunk_sum'),
                ParamDefinition::required(name: 'chunks', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional(name: 'category', default: null, type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional(name: 'level', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'image_id', default: null, type: WS_TYPE_ID),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.delete',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->delete(...),
            description:  'Deletes image(s).',
            params:       [
                ParamDefinition::required(name: 'image_id', flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setMd5sum',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->setMd5sum(...),
            description:  'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.',
            params:       [
                ParamDefinition::optional(name: 'block_size', default: Config::checksumComputeBlocksize(), type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.syncMetadata',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->syncMetadata(...),
            description:  'Sync metadatas, by blocks. Returns how many images were synchronized',
            params:       [
                ParamDefinition::required(name: 'image_id', flags: WS_PARAM_ACCEPT_ARRAY, info: 'Comma separated ids or array of id'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.deleteOrphans',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->deleteOrphans(...),
            description:  'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.',
            params:       [
                ParamDefinition::optional(name: 'block_size', default: 1000, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['images'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.calculateOrphans',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->calculateOrphans(...),
            description:  'Return the number of orphan photos if an album is deleted.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.getAdminList',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->getAdminList(...),
            description:  'Get albums list as displayed on admin page. <br>
      <b>additional_output</b> controls which data are returned, possible values are:<br>
      null, full_name_with_admin_links<br>',
            params:       [
                ParamDefinition::optional(name: 'cat_id', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE, info: 'Parent category. "0" or empty for root.'),
                ParamDefinition::optional('search'),
                ParamDefinition::optional(name: 'recursive', default: true, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'additional_output', default: null, info: 'Comma saparated list (see method description)'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.add',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->add(...),
            description:  'Adds an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            params:       [
                ParamDefinition::required('name'),
                ParamDefinition::optional(name: 'parent', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'visible', default: true, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'status', default: null, info: 'public, private'),
                ParamDefinition::optional(name: 'commentable', default: true, type: WS_TYPE_BOOL),
                ParamDefinition::optional(name: 'position', default: null, info: 'first, last'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.delete',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->delete(...),
            description:  'Deletes album(s).
<br><b>photo_deletion_mode</b> can be "no_delete" (may create orphan photos), "delete_orphans"
(default mode, only deletes photos linked to no other album) or "force_delete" (delete all photos, even those linked to other albums)',
            params:       [
                ParamDefinition::required(name: 'category_id', flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::optional(name: 'photo_deletion_mode', default: 'delete_orphans'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.move',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->move(...),
            description:  'Move album(s).
<br>Set parent as 0 to move to gallery root. Only virtual categories can be moved.',
            params:       [
                ParamDefinition::required(name: 'category_id', flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::required(name: 'parent', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.setRepresentative',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->setRepresentative(...),
            description:  'Sets the representative photo for an album. The photo doesn\'t have to belong to the album.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.deleteRepresentative',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->deleteRepresentative(...),
            description:  'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.categories.refreshRepresentative',
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->refreshRepresentative(...),
            description:  'Find a new album thumbnail.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.getAdminList',
            callback:     ServiceLocator::get(TagsEndpoints::class)->getAdminList(...),
            description:  '<b>Admin only.</b>',
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.add',
            callback:     ServiceLocator::get(TagsEndpoints::class)->add(...),
            description:  'Adds a new tag.',
            params:       [
                ParamDefinition::required('name'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.delete',
            callback:     ServiceLocator::get(TagsEndpoints::class)->delete(...),
            description:  'Delete tag(s) by ID.',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.rename',
            callback:     ServiceLocator::get(TagsEndpoints::class)->rename(...),
            description:  'Rename tag',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WS_TYPE_ID),
                ParamDefinition::required('new_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.duplicate',
            callback:     ServiceLocator::get(TagsEndpoints::class)->duplicate(...),
            description:  'Create a copy of a tag',
            params:       [
                ParamDefinition::required(name: 'tag_id', type: WS_TYPE_ID),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.tags.merge',
            callback:     ServiceLocator::get(TagsEndpoints::class)->merge(...),
            description:  'Merge tags in one other group',
            params:       [
                ParamDefinition::required(name: 'destination_tag_id', type: WS_TYPE_ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required(name: 'merge_tag_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['tags'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.exist',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->exist(...),
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
            callback:     ServiceLocator::get(ImagesEndpoints::class)->checkFiles(...),
            description:  'Checks if you have updated version of your files for a given photo, the answer can be "missing", "equals" or "differs".
<br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional('file_sum'),
                ParamDefinition::optional('thumbnail_sum'),
                ParamDefinition::optional('high_sum'),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.checkUpload',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->checkUpload(...),
            description:  'Checks if Piwigo is ready for upload.',
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.emptyLounge',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->emptyLounge(...),
            description:  'Empty lounge, where images may be waiting before taking off.',
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.uploadCompleted',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->uploadCompleted(...),
            description:  'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.',
            params:       [
                ParamDefinition::optional(name: 'image_id', default: null, flags: WS_PARAM_ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
            ],
            tags:         ['images'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.images.setInfo',
            callback:     ServiceLocator::get(ImagesEndpoints::class)->setInfo(...),
            description:  'Changes properties of an image.
<br><b>single_value_mode</b> can be "fill_if_empty" (only use the input value if the corresponding values is currently empty) or "replace"
(overwrite any existing value) and applies to single values properties like name/author/date_creation/comment.
<br><b>multiple_value_mode</b> can be "append" (no change on existing values, add the new values) or "replace" and applies to multiple values properties like tag_ids/categories.
<br><b>pwg_token</b> required if you want to use HTML in name/comment/author.',
            params:       [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional('file'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional(name: 'categories', default: null, info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional(name: 'tag_ids', default: null, info: 'Comma separated ids'),
                ParamDefinition::optional(name: 'level', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
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
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->setInfo(...),
            description:  'Changes properties of an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID),
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
            callback:     ServiceLocator::get(CategoriesEndpoints::class)->setRank(...),
            description:  'Changes the rank of an album
        <br><br>If you provide a list for category_id:
        <ul>
        <li>rank becomes useless, only the order of the image_id list matters</li>
        <li>you are supposed to provide the list of all categories_ids belonging to the album.
        </ul>.',
            params:       [
                ParamDefinition::required(name: 'category_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'rank', type: WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL),
            ],
            tags:         ['categories'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.plugins.getList',
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->pluginsGetList(...),
            description:  'Gets the list of plugins with id, name, version, state and description.',
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.plugins.performAction',
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->pluginsPerformAction(...),
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
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->themesPerformAction(...),
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
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->update(...),
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
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->ignoreUpdate(...),
            description:  '<b>Webmaster only.</b> Ignores an extension if it needs update.',
            params:       [
                ParamDefinition::optional(name: 'type', default: null, info: 'plugins, languages, themes'),
                ParamDefinition::optional('id'),
                ParamDefinition::optional(name: 'reset', default: false, type: WS_TYPE_BOOL, info: 'If true, all ignored extensions will be reinitilized.'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.extensions.checkUpdates',
            callback:     ServiceLocator::get(ExtensionsEndpoints::class)->checkUpdates(...),
            description:  'Checks if piwigo or extensions are up to date.',
            tags:         ['extensions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.getList',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->getList(...),
            description:  'Retrieves a list of all groups. The list can be filtered.',
            params:       [
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'name', info: 'Use "%" as wildcard.'),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxUsersPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: 'name', info: 'id, name, nb_users, is_default'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.add',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->add(...),
            description:  'Creates a group and returns the new group record.',
            params:       [
                ParamDefinition::required('name'),
                ParamDefinition::optional(name: 'is_default', default: false, type: WS_TYPE_BOOL),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.delete',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->delete(...),
            description:  'Deletes a or more groups. Users and photos are not deleted.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.setInfo',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->setInfo(...),
            description:  'Updates a group. Leave a field blank to keep the current value.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WS_TYPE_ID),
                ParamDefinition::optionalFlag('name'),
                ParamDefinition::optionalFlag(name: 'is_default', type: WS_TYPE_BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.addUser',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->addUser(...),
            description:  'Adds one or more users to a group.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WS_TYPE_ID),
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.deleteUser',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->deleteUser(...),
            description:  'Removes one or more users from a group.',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WS_TYPE_ID),
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.merge',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->merge(...),
            description:  'Merge groups in one other group',
            params:       [
                ParamDefinition::required(name: 'destination_group_id', type: WS_TYPE_ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required(name: 'merge_group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.groups.duplicate',
            callback:     ServiceLocator::get(GroupsEndpoints::class)->duplicate(...),
            description:  'Create a copy of a group',
            params:       [
                ParamDefinition::required(name: 'group_id', type: WS_TYPE_ID),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['groups'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.getList',
            callback:     ServiceLocator::get(UsersEndpoints::class)->getList(...),
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
                ParamDefinition::optionalFlag(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'username', info: 'Use "%" as wildcard.'),
                ParamDefinition::optionalFlag(name: 'status', flags: WS_PARAM_FORCE_ARRAY, info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optional(name: 'min_level', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxUsersPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: 'id', info: 'id, username, level, email'),
                ParamDefinition::optionalFlag(name: 'exclude', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY, info: 'Expects a user_id as value.'),
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
            callback:     ServiceLocator::get(UsersEndpoints::class)->add(...),
            description:  'Registers a new user.',
            params:       [
                ParamDefinition::required('username'),
                ParamDefinition::optional(name: 'auto_password', default: false, info: 'if true ignores password and confirm password'),
                ParamDefinition::optional('password'),
                ParamDefinition::optionalFlag('password_confirm'),
                ParamDefinition::optional('email'),
                ParamDefinition::optional(name: 'send_password_by_mail', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.delete',
            callback:     ServiceLocator::get(UsersEndpoints::class)->delete(...),
            description:  'Deletes on or more users. Photos owned by this user are not deleted.',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.getAuthKey',
            callback:     ServiceLocator::get(UsersEndpoints::class)->getAuthKey(...),
            description:  'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.setInfo',
            callback:     ServiceLocator::get(UsersEndpoints::class)->setInfo(...),
            description:  'Updates a user. Leave a field blank to keep the current value.
<br>"username", "password" and "email" are ignored if "user_id" is an array.
<br>set "group_id" to -1 if you want to dissociate users from all groups',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag(name: 'status', info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optionalFlag(name: 'level', type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: max(Config::availablePermissionLevels())),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_INT, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'nb_image_page', type: WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL),
                ParamDefinition::optionalFlag(name: 'recent_period', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'expand', type: WS_TYPE_BOOL),
                ParamDefinition::optionalFlag(name: 'show_nb_comments', type: WS_TYPE_BOOL),
                ParamDefinition::optionalFlag(name: 'show_nb_hits', type: WS_TYPE_BOOL),
                ParamDefinition::optionalFlag(name: 'enabled_high', type: WS_TYPE_BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.setMyInfo',
            callback:    ServiceLocator::get(UsersEndpoints::class)->setMyInfo(...),
            params:      [
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag(name: 'nb_image_page', type: WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag(name: 'recent_period', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'expand', type: WS_TYPE_BOOL),
                ParamDefinition::optionalFlag(name: 'show_nb_comments', type: WS_TYPE_BOOL),
                ParamDefinition::optionalFlag(name: 'show_nb_hits', type: WS_TYPE_BOOL),
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
            callback:     ServiceLocator::get(PermissionsEndpoints::class)->getList(...),
            description:  'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
<br>Provide only one parameter!',
            params:       [
                ParamDefinition::optionalFlag(name: 'cat_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.permissions.add',
            callback:     ServiceLocator::get(PermissionsEndpoints::class)->add(...),
            description:  'Adds permissions to an album.',
            params:       [
                ParamDefinition::required(name: 'cat_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'recursive', default: false, type: WS_TYPE_BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.permissions.remove',
            callback:     ServiceLocator::get(PermissionsEndpoints::class)->remove(...),
            description:  'Removes permissions from an album.',
            params:       [
                ParamDefinition::required(name: 'cat_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'group_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'user_id', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['permissions'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.preferences.set',
            callback:    ServiceLocator::get(UsersEndpoints::class)->preferencesSet(...),
            description: 'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.',
            params:      [
                ParamDefinition::required('param'),
                ParamDefinition::optionalFlag('value'),
                ParamDefinition::optional(name: 'is_json', default: false, type: WS_TYPE_BOOL),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.add',
            callback:    ServiceLocator::get(UsersEndpoints::class)->favoritesAdd(...),
            description: 'Adds the indicated image to the current user\'s favorite images.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.remove',
            callback:    ServiceLocator::get(UsersEndpoints::class)->favoritesRemove(...),
            description: 'Removes the indicated image from the current user\'s favorite images.',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.favorites.getList',
            callback:    ServiceLocator::get(UsersEndpoints::class)->favoritesGetList(...),
            description: 'Returns the favorite images of the current user.',
            params:      [
                ParamDefinition::optional(name: 'per_page', default: 100, type: WS_TYPE_INT | WS_TYPE_POSITIVE, maxValue: Config::wsMaxImagesPerPage()),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'order', default: null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
            ],
            tags:        ['users'],
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.history.log',
            callback:    ServiceLocator::get(GeneralEndpoints::class)->historyLog(...),
            description: 'Log visit in history',
            params:      [
                ParamDefinition::required(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional(name: 'cat_id', default: null, type: WS_TYPE_ID),
                ParamDefinition::optional('section'),
                ParamDefinition::optional('tags_string'),
                ParamDefinition::optional(name: 'is_download', default: false, type: WS_TYPE_BOOL),
            ],
            tags:        ['history'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.history.search',
            callback:     ServiceLocator::get(GeneralEndpoints::class)->historySearch(...),
            description:  'Gives an history of who has visited the galery and the actions done in it. Receives parameter.
      <br> <strong>Types </strong> can be : \'none\', \'picture\', \'high\', \'other\'
      <br> <strong>Date format</strong> is yyyy-mm-dd
      <br> <strong>display_thumbnail</strong> can be : \'no_display_thumbnail\', \'display_thumbnail_classic\', \'display_thumbnail_hoverbox\'',
            params:       [
                ParamDefinition::optional('start'),
                ParamDefinition::optional('end'),
                ParamDefinition::optional(name: 'types', default: ['none', 'picture', 'high', 'other'], flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optional(name: 'user_id', default: -1),
                ParamDefinition::optional(name: 'image_id', default: null, type: WS_TYPE_ID),
                ParamDefinition::optional('filename'),
                ParamDefinition::optional('ip'),
                ParamDefinition::optional(name: 'display_thumbnail', default: 'display_thumbnail_classic'),
                ParamDefinition::optional(name: 'pageNumber', default: null, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ],
            tags:         ['history'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.images.filteredSearch.create',
            callback:    ServiceLocator::get(ImagesEndpoints::class)->filteredSearchCreate(...),
            params:      [
                ParamDefinition::optionalFlag(name: 'search_id', info: 'prior search_id (or search_key), if any'),
                ParamDefinition::optionalFlag(name: 'allwords', info: 'query to search by words'),
                ParamDefinition::optionalFlag(name: 'allwords_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag(name: 'allwords_fields', flags: WS_PARAM_FORCE_ARRAY, info: 'values among [name, comment, tags, file, author, cat-title, cat-desc]'),
                ParamDefinition::optionalFlag(name: 'tags', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'tags_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag(name: 'categories', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'categories_withsubs', type: WS_TYPE_BOOL, info: 'false, by default'),
                ParamDefinition::optionalFlag(name: 'authors', flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'added_by', type: WS_TYPE_ID, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'filetypes', flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'date_posted_preset', info: 'files posted within 24 hours, 7 days, 30 days, 3 months, 6 months or custom. Value among 24h|7d|30d|3m|6m|custom.'),
                ParamDefinition::optionalFlag(name: 'date_posted_custom', flags: WS_PARAM_FORCE_ARRAY, info: 'Must be provided if date_posted_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag(name: 'date_created_preset', info: 'files created within 7 days, 30 days, 3 months, 6 months, 12 months or custom. Value among 7d|30d|3m|6m|12m|custom.'),
                ParamDefinition::optionalFlag(name: 'date_created_custom', flags: WS_PARAM_FORCE_ARRAY, info: 'Must be provided if date_created_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag(name: 'ratios', flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'ratings', flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::optionalFlag(name: 'filesize_min', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'filesize_max', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'height_min', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'height_max', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'width_min', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optionalFlag(name: 'width_max', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ],
            tags:        ['images'],
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.generatePasswordLink',
            callback:     ServiceLocator::get(UsersEndpoints::class)->generatePasswordLink(...),
            description:  'Return the reset password link <br />
       (Only webmaster can perform this action for another webmaster)',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::optional(name: 'send_by_mail', default: false, type: WS_TYPE_BOOL),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.users.setMainUser',
            callback:     ServiceLocator::get(UsersEndpoints::class)->setMainUser(...),
            description:  'Update the main user (owner) <br />
        - To be the main user, the user must have the status "webmaster".<br />
        - Only a webmaster can perform this action',
            params:       [
                ParamDefinition::required(name: 'user_id', type: WS_TYPE_ID),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['users'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.create',
            callback:    ServiceLocator::get(UsersEndpoints::class)->createApiKey(...),
            description: 'Create a new api key for the user in the current session',
            params:      [
                ParamDefinition::required('key_name'),
                ParamDefinition::required(name: 'duration', type: WS_TYPE_INT | WS_TYPE_POSITIVE, info: 'Number of days'),
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:        'pwg.users.api_key.revoke',
            callback:    ServiceLocator::get(UsersEndpoints::class)->revokeApiKey(...),
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
            callback:    ServiceLocator::get(UsersEndpoints::class)->editApiKey(...),
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
            callback:    ServiceLocator::get(UsersEndpoints::class)->getApiKey(...),
            description: 'Get all api key for the user in the current session',
            params:      [
                ParamDefinition::required('pwg_token'),
            ],
            tags:        ['users'],
            postOnly:    true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.getList',
            callback:     ServiceLocator::get(CommentsEndpoints::class)->getList(...),
            description:  'Get comments',
            params:       [
                ParamDefinition::optional(name: 'status', default: 'all', info: 'must be: all, validated or pending'),
                ParamDefinition::optional(name: 'search', default: null, info: 'All other parameters are not used during a search.'),
                ParamDefinition::optionalFlag(name: 'author_id', type: WS_TYPE_ID),
                ParamDefinition::optionalFlag(name: 'image_id', type: WS_TYPE_ID),
                ParamDefinition::optional('f_min_date'),
                ParamDefinition::optional('f_max_date'),
                ParamDefinition::optional(name: 'page', default: 0, type: WS_TYPE_INT | WS_TYPE_POSITIVE),
                ParamDefinition::optional(name: 'per_page', default: Config::commentsPageNbComments(), type: WS_TYPE_INT | WS_TYPE_POSITIVE),
            ],
            tags:         ['comments'],
            requiresAuth: true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.delete',
            callback:     ServiceLocator::get(CommentsEndpoints::class)->delete(...),
            description:  'Delete comments',
            params:       [
                ParamDefinition::required(name: 'comment_id', type: WS_TYPE_INT | WS_TYPE_POSITIVE, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['comments'],
            requiresAuth: true,
            postOnly:     true,
        ));

        $server->register(new MethodDefinition(
            name:         'pwg.userComments.validate',
            callback:     ServiceLocator::get(CommentsEndpoints::class)->validate(...),
            description:  'Validate comments',
            params:       [
                ParamDefinition::required(name: 'comment_id', type: WS_TYPE_INT | WS_TYPE_POSITIVE, flags: WS_PARAM_FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            tags:         ['comments'],
            requiresAuth: true,
            postOnly:     true,
        ));
    }
}
