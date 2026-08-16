<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\SharedImageFilterParams;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.images*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class ImagesMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
    ) {}

    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.addComment',
            handlerClass: AddCommentHandler::class,
            description: 'Adds a comment to an image.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('author', $this->accessControl->isAGuest() ? 'guest' : $this->currentUser->get()->username),
                ParamDefinition::required('content'),
                ParamDefinition::required('key'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.getInfo',
            handlerClass: GetInfoHandler::class,
            description: 'Returns information about an image.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('comments_page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('comments_per_page', $this->currentConfig->nbCommentPage, WsParamType::INT | WsParamType::POSITIVE, maxValue: 2 * $this->currentConfig->nbCommentPage),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.rate',
            handlerClass: RateHandler::class,
            description: 'Rates an image.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::required('rate', WsParamType::FLOAT),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.search',
            handlerClass: SearchHandler::class,
            description: 'Returns elements for the corresponding query search.',
            params: [
                ParamDefinition::required('query'),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...SharedImageFilterParams::get(),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.setPrivacyLevel',
            handlerClass: SetPrivacyLevelHandler::class,
            description: 'Sets the privacy levels for the images.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('level', WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.formats.searchImage',
            handlerClass: FormatsSearchImageHandler::class,
            description: 'Search for image ids matching the provided filenames. <b>filename_list</b> must be a JSON encoded associative array of unique_id:filename.<br><br>The method returns a list of unique_id:image_id.',
            params: [
                ParamDefinition::required('filename_list'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.formats.delete',
            handlerClass: FormatsDeleteHandler::class,
            description: 'Remove a format',
            params: [
                ParamDefinition::optional('format_id', null, WsParamType::ID, WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.setRank',
            handlerClass: SetRankHandler::class,
            description: 'Sets the rank of a photo for a given album.
    <br><br>If you provide a list for image_id:
    <ul>
    <li>rank becomes useless, only the order of the image_id list matters</li>
    <li>you are supposed to provide the list of all image_ids belonging to the album.
    </ul>',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('category_id', WsParamType::ID),
                ParamDefinition::optional('rank', null, WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.setCategory',
            handlerClass: SetCategoryHandler::class,
            description: 'Manage associations of images with an album. <b>action</b> can be:<ul><li><i>associate</i> : add photos to this album</li><li><i>dissociate</i> : remove photos from this album</li><li><i>move</i> : dissociate photos from any other album and adds photos to this album</li></ul>',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('category_id', WsParamType::ID),
                ParamDefinition::optional('action', 'associate', info: 'associate/dissociate/move'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.addChunk',
            handlerClass: AddChunkHandler::class,
            description: 'Add a chunk of a file.',
            params: [
                ParamDefinition::required('data'),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional('type', 'file', info: 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.'),
                ParamDefinition::required('position'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.addFile',
            handlerClass: AddFileHandler::class,
            description: 'Add or update a file for an existing photo.
    <br>pwg.images.addChunk must have been called before (maybe several times).',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('type', 'file', info: 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.'),
                ParamDefinition::required('sum'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.add',
            handlerClass: AddHandler::class,
            description: 'Add an image.
    <br>pwg.images.addChunk must have been called before (maybe several times).
    <br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            params: [
                ParamDefinition::optional('thumbnail_sum'),
                ParamDefinition::optional('high_sum'),
                ParamDefinition::required('original_sum'),
                // The original registration's own 'info' value here is a
                // stray unkeyed array element (a pre-existing typo -- see
                // git history), so it was already silently discarded by
                // the old addMethod()-based registration and never
                // surfaced via pwg.getMethodDetails; not carried over here
                // either.
                ParamDefinition::optional('original_filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('categories', info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional('tag_ids', info: 'Comma separated ids'),
                ParamDefinition::optional('level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optional('check_uniqueness', true, WsParamType::BOOL),
                ParamDefinition::optional('image_id', null, WsParamType::ID),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.addSimple',
            handlerClass: AddSimpleHandler::class,
            description: 'Add an image.
    <br>Use the <b>$_FILES[image]</b> field for uploading file.
    <br>Set the form encoding to "form-data".
    <br>You can update an existing photo if you define an existing image_id.',
            params: [
                ParamDefinition::optional('category', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optional('tags', flags: WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::optional('image_id', null, WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.upload',
            handlerClass: UploadHandler::class,
            description: 'Add an image.
    <br>Use the <b>$_FILES[image]</b> field for uploading file.
    <br>Set the form encoding to "form-data".',
            params: [
                ParamDefinition::optional('name'),
                ParamDefinition::optional('category', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optional('format_of', null, WsParamType::ID, info: 'id of the extended image (name/category/level are not used if format_of is provided)'),
                ParamDefinition::optional('update_mode', false, WsParamType::BOOL, info: 'true if the update mode is active'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.uploadAsync',
            handlerClass: UploadAsyncHandler::class,
            description: 'Upload photo by chunks in a random order.
    <br>Use the <b>$_FILES[file]</b> field for uploading file.
    <br>Start with chunk 0 (zero).
    <br>Set the form encoding to "form-data".
    <br>You can update an existing photo if you define an existing image_id.
    <br>Requires <b>admin</b> credentials: either with username/password or header authorization with api key.',
            params: [
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::required('chunk', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::required('chunk_sum'),
                ParamDefinition::required('chunks', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::required('original_sum'),
                ParamDefinition::optional('category', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('filename'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optional('tag_ids', info: 'Comma separated ids'),
                ParamDefinition::optional('image_id', null, WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.delete',
            handlerClass: DeleteHandler::class,
            description: 'Deletes image(s).',
            params: [
                ParamDefinition::required('image_id', flags: WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.setMd5sum',
            handlerClass: SetMd5sumHandler::class,
            description: 'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.',
            params: [
                ParamDefinition::optional('block_size', $this->currentConfig->checksumComputeBlocksize, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.syncMetadata',
            handlerClass: SyncMetadataHandler::class,
            description: 'Sync metadatas, by blocks. Returns how many images were synchronized',
            params: [
                ParamDefinition::required('image_id', flags: WsParamFlag::ACCEPT_ARRAY, info: 'Comma separated ids or array of id'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.deleteOrphans',
            handlerClass: DeleteOrphansHandler::class,
            description: 'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.',
            params: [
                ParamDefinition::optional('block_size', 1000, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.exist',
            handlerClass: ExistHandler::class,
            description: 'Checks existence of images.
    <br>Give <b>md5sum_list</b> if $conf[uniqueness_mode]==md5sum. Give <b>filename_list</b> if $conf[uniqueness_mode]==filename.',
            params: [
                ParamDefinition::optional('md5sum_list'),
                ParamDefinition::optional('filename_list'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.checkFiles',
            handlerClass: CheckFilesHandler::class,
            description: 'Checks if you have updated version of your files for a given photo, the answer can be "missing", "equals" or "differs".
    <br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('file_sum'),
                ParamDefinition::optional('thumbnail_sum'),
                ParamDefinition::optional('high_sum'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.checkUpload',
            handlerClass: CheckUploadHandler::class,
            description: 'Checks if Piwigo is ready for upload.',
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.emptyLounge',
            handlerClass: EmptyLoungeHandler::class,
            description: 'Empty lounge, where images may be waiting before taking off.',
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.uploadCompleted',
            handlerClass: UploadCompletedHandler::class,
            description: 'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.',
            params: [
                ParamDefinition::optional('image_id', null, flags: WsParamFlag::ACCEPT_ARRAY),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::required('category_id', WsParamType::ID),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.setInfo',
            handlerClass: SetInfoHandler::class,
            description: 'Changes properties of an image.
    <br><b>single_value_mode</b> can be "fill_if_empty" (only use the input value if the corresponding values is currently empty) or "replace"
    (overwrite any existing value) and applies to single values properties like name/author/date_creation/comment.
    <br><b>multiple_value_mode</b> can be "append" (no change on existing values, add the new values) or "replace" and applies to multiple values properties like tag_ids/categories.
    <br><b>pwg_token</b> required. HTML in name/comment/author is allowed only when the allow_html_descriptions config is enabled.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
                ParamDefinition::optional('file'),
                ParamDefinition::optional('name'),
                ParamDefinition::optional('author'),
                ParamDefinition::optional('date_creation'),
                ParamDefinition::optional('comment'),
                ParamDefinition::optional('categories', info: 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
                ParamDefinition::optional('tag_ids', info: 'Comma separated ids'),
                ParamDefinition::optional('level', null, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optional('single_value_mode', 'fill_if_empty'),
                ParamDefinition::optional('multiple_value_mode', 'append'),
                ParamDefinition::optionalFlag('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.images.filteredSearch.create',
            handlerClass: FilteredSearchCreateHandler::class,
            params: [
                ParamDefinition::optionalFlag('search_id', info: 'prior search_id (or search_key), if any'),
                ParamDefinition::optionalFlag('allwords', info: 'query to search by words'),
                ParamDefinition::optionalFlag('allwords_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag('allwords_fields', flags: WsParamFlag::FORCE_ARRAY, info: 'values among [name, comment, tags, file, author, cat-title, cat-desc]'),
                ParamDefinition::optionalFlag('tags', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('tags_mode', info: 'AND (by default) | OR'),
                ParamDefinition::optionalFlag('categories', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('categories_withsubs', WsParamType::BOOL, info: 'false, by default'),
                ParamDefinition::optionalFlag('authors', flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('added_by', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('filetypes', flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('date_posted_preset', info: 'files posted within 24 hours, 7 days, 30 days, 3 months, 6 months or custom. Value among 24h|7d|30d|3m|6m|custom.'),
                ParamDefinition::optionalFlag('date_posted_custom', flags: WsParamFlag::FORCE_ARRAY, info: 'Must be provided if date_posted_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag('date_created_preset', info: 'files created within 7 days, 30 days, 3 months, 6 months, 12 months or custom. Value among 7d|30d|3m|6m|12m|custom.'),
                ParamDefinition::optionalFlag('date_created_custom', flags: WsParamFlag::FORCE_ARRAY, info: 'Must be provided if date_created_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.'),
                ParamDefinition::optionalFlag('ratios', flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('ratings', flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('filesize_min', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('filesize_max', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('height_min', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('height_max', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('width_min', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('width_max', WsParamType::INT | WsParamType::POSITIVE),
            ],
        ));
    }

    /**
     * Guards against a misconfigured/empty value since max() requires a
     * non-empty array -- moved here from the old WsDefaultMethods::register(),
     * duplicated in ImagesMethodRegistrar/UsersMethodRegistrar (its only 2
     * real consumers) rather than factored into a third shared class, same
     * "small duplication over cross-cutting shared state" precedent as this
     * codebase already uses elsewhere.
     *
     * @return non-empty-list<int>
     */
    private static function availablePermissionLevels(CurrentConfig $currentConfig): array
    {
        $levels = $currentConfig->availablePermissionLevels;

        return $levels !== [] ? $levels : [0, 1, 2, 4, 8];
    }
}
