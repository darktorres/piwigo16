<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsParamFlag;
use Piwigo\Core\WsParamType;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Categories\AddHandler as CategoriesAddHandler;
use Piwigo\Ws\Categories\CalculateOrphansHandler;
use Piwigo\Ws\Categories\DeleteHandler as CategoriesDeleteHandler;
use Piwigo\Ws\Categories\DeleteRepresentativeHandler;
use Piwigo\Ws\Categories\GetAdminListHandler as CategoriesGetAdminListHandler;
use Piwigo\Ws\Categories\GetImagesHandler as CategoriesGetImagesHandler;
use Piwigo\Ws\Categories\GetListHandler as CategoriesGetListHandler;
use Piwigo\Ws\Categories\MoveHandler;
use Piwigo\Ws\Categories\RefreshRepresentativeHandler;
use Piwigo\Ws\Categories\SetInfoHandler as CategoriesSetInfoHandler;
use Piwigo\Ws\Categories\SetRankHandler;
use Piwigo\Ws\Categories\SetRepresentativeHandler;
use Piwigo\Ws\Comments\DeleteHandler as CommentsDeleteHandler;
use Piwigo\Ws\Comments\GetListHandler as CommentsGetListHandler;
use Piwigo\Ws\Comments\ValidateHandler as CommentsValidateHandler;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Extensions\CheckUpdatesHandler;
use Piwigo\Ws\Extensions\IgnoreUpdateHandler;
use Piwigo\Ws\Extensions\PluginsGetListHandler;
use Piwigo\Ws\Extensions\PluginsPerformActionHandler;
use Piwigo\Ws\Extensions\ThemesPerformActionHandler;
use Piwigo\Ws\Extensions\UpdateHandler;
use Piwigo\Ws\Groups\AddHandler as GroupsAddHandler;
use Piwigo\Ws\Groups\AddUserHandler as GroupsAddUserHandler;
use Piwigo\Ws\Groups\DeleteHandler as GroupsDeleteHandler;
use Piwigo\Ws\Groups\DeleteUserHandler as GroupsDeleteUserHandler;
use Piwigo\Ws\Groups\DuplicateHandler as GroupsDuplicateHandler;
use Piwigo\Ws\Groups\GetListHandler as GroupsGetListHandler;
use Piwigo\Ws\Groups\MergeHandler as GroupsMergeHandler;
use Piwigo\Ws\Groups\SetInfoHandler as GroupsSetInfoHandler;
use Piwigo\Ws\Permissions\AddHandler as PermissionsAddHandler;
use Piwigo\Ws\Permissions\GetListHandler as PermissionsGetListHandler;
use Piwigo\Ws\Permissions\RemoveHandler as PermissionsRemoveHandler;
use Piwigo\Ws\Tags\AddHandler as TagsAddHandler;
use Piwigo\Ws\Tags\DeleteHandler as TagsDeleteHandler;
use Piwigo\Ws\Tags\DuplicateHandler as TagsDuplicateHandler;
use Piwigo\Ws\Tags\GetAdminListHandler as TagsGetAdminListHandler;
use Piwigo\Ws\Tags\GetImagesHandler as TagsGetImagesHandler;
use Piwigo\Ws\Tags\GetListHandler as TagsGetListHandler;
use Piwigo\Ws\Tags\MergeHandler as TagsMergeHandler;
use Piwigo\Ws\Tags\RenameHandler as TagsRenameHandler;
use Piwigo\Ws\Users\AddHandler as UsersAddHandler;
use Piwigo\Ws\Users\CreateApiKeyHandler;
use Piwigo\Ws\Users\DeleteHandler as UsersDeleteHandler;
use Piwigo\Ws\Users\EditApiKeyHandler;
use Piwigo\Ws\Users\FavoritesAddHandler;
use Piwigo\Ws\Users\FavoritesGetListHandler;
use Piwigo\Ws\Users\FavoritesRemoveHandler;
use Piwigo\Ws\Users\GeneratePasswordLinkHandler;
use Piwigo\Ws\Users\GetApiKeyHandler;
use Piwigo\Ws\Users\GetAuthKeyHandler;
use Piwigo\Ws\Users\GetListHandler as UsersGetListHandler;
use Piwigo\Ws\Users\PreferencesSetHandler;
use Piwigo\Ws\Users\RevokeApiKeyHandler;
use Piwigo\Ws\Users\SetInfoHandler as UsersSetInfoHandler;
use Piwigo\Ws\Users\SetMainUserHandler;
use Piwigo\Ws\Users\SetMyInfoHandler;

final readonly class WsDefaultMethods
{
    // Each Pwg* class used by register() is injected as a constructor
    // property; the property list here and the instance-method callbacks
    // registered below must stay in sync -- register() calls these as real
    // instance methods (e.g. $this->pwgCore->getVersion(...)), not static
    // ClassName::method() calls. `pwg.userComments.*`/`pwg.permissions.*`/
    // `pwg.plugins.*`/`pwg.themes.performAction`/`pwg.extensions.*`/
    // `pwg.groups.*`/`pwg.tags.*`/`pwg.categories.*`/`pwg.users.*`
    // (Comments.php/Permissions.php/Extensions.php/Groups.php/Tags.php/
    // Categories.php/Users.php, Group 19's first 7 migrated domains) no
    // longer have a callback-based registration or a constructor
    // property here -- their methods register via
    // MethodDefinition/handlerClass instead, resolved from the container
    // at invocation time.
    public function __construct(
        private Core $pwgCore,
        private Images $pwgImages,
        private CurrentConfig $currentConfig,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private ImageStdParams $imageStdParams,
    ) {}

    /**
     * The MethodDefinition/ParamDefinition equivalent of $f_params below
     * (the shared images-table range-filter block merged into several
     * still-addMethod()-registered methods' own params) -- kept as a
     * separate method rather than replacing $f_params itself, since
     * addMethod() and register() need the same 11 filter params in two
     * different shapes and not every method using them has migrated yet.
     *
     * @return list<ParamDefinition>
     */
    private static function sharedImageFilterParams(): array
    {
        return [
            ParamDefinition::optional('f_min_rate', null, WsParamType::FLOAT),
            ParamDefinition::optional('f_max_rate', null, WsParamType::FLOAT),
            ParamDefinition::optional('f_min_hit', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_hit', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_min_ratio', null, WsParamType::FLOAT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_ratio', null, WsParamType::FLOAT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_level', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_min_date_available'),
            ParamDefinition::optional('f_max_date_available'),
            ParamDefinition::optional('f_min_date_created'),
            ParamDefinition::optional('f_max_date_created'),
        ];
    }

    /**
     * event handler that registers standard methods with the web service
     */
    public function register(WsAddMethods $event): void
    {
        $service = $event->server;

        // guard against a misconfigured/empty value since max() requires a
        // non-empty array.
        $available_permission_levels = $this->currentConfig->availablePermissionLevels;
        $available_permission_levels = $available_permission_levels !== []
            ? $available_permission_levels
            : [0, 1, 2, 4, 8];

        // $this->currentConfig->nbCommentPage is a numeric config value (see admin/configuration.php).
        $nb_comment_page = $this->currentConfig->nbCommentPage;

        $f_params = [
            'f_min_rate' => [
                'default' => null,
                'type' => WsParamType::FLOAT,
            ],
            'f_max_rate' => [
                'default' => null,
                'type' => WsParamType::FLOAT,
            ],
            'f_min_hit' => [
                'default' => null,
                'type' => WsParamType::INT | WsParamType::POSITIVE,
            ],
            'f_max_hit' => [
                'default' => null,
                'type' => WsParamType::INT | WsParamType::POSITIVE,
            ],
            'f_min_ratio' => [
                'default' => null,
                'type' => WsParamType::FLOAT | WsParamType::POSITIVE,
            ],
            'f_max_ratio' => [
                'default' => null,
                'type' => WsParamType::FLOAT | WsParamType::POSITIVE,
            ],
            'f_max_level' => [
                'default' => null,
                'type' => WsParamType::INT | WsParamType::POSITIVE,
            ],
            'f_min_date_available' => [
                'default' => null,
            ],
            'f_max_date_available' => [
                'default' => null,
            ],
            'f_min_date_created' => [
                'default' => null,
            ],
            'f_max_date_created' => [
                'default' => null,
            ],
        ];

        $service->addMethod(
            'pwg.getVersion',
            $this->pwgCore->getVersion(...),
            null,
            'Returns the Piwigo version.'
        );

        $service->addMethod(
            'pwg.getInfos',
            $this->pwgCore->getInfos(...),
            null,
            'Returns general informations.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.getCacheSize',
            $this->pwgCore->getCacheSize(...),
            null,
            'Returns general informations.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.activity.getList',
            $this->pwgCore->getActivityList(...),
            [
                'page' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'offset' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'uid' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
                'date_min' => [
                    'default' => null,
                ],
                'date_max' => [
                    'default' => null,
                ],
                'id' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
                'object' => [
                    'default' => null,
                ],
                'action' => [
                    'default' => null,
                ],
            ],
            'Returns general informations.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.activity.downloadLog',
            // 'ws_activity_downloadLog' is not a defined function -- this
            // registration fatals with "call to undefined function" if
            // ever invoked.
            'ws_activity_downloadLog',
            null,
            'Returns general informations.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.caddie.add',
            $this->pwgCore->caddieAdd(...),
            [
                'image_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
            ],
            'Adds elements to the caddie. Returns the number of elements added.',
            options: [
                'admin_only' => true,
            ]
        );

        // pwg.categories.* (Group 19's sixth migrated domain) registers via
        // MethodDefinition/handlerClass -- Categories.php is gone, each
        // method is its own container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.categories.getImages',
            handlerClass: CategoriesGetImagesHandler::class,
            description: 'Returns elements for the corresponding categories.
    <br><b>cat_id</b> can be empty if <b>recursive</b> is true.
    <br><b>order</b> comma separated fields for sorting',
            params: [
                ParamDefinition::optional('cat_id', null, WsParamType::INT | WsParamType::POSITIVE, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('recursive', false, WsParamType::BOOL),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...self::sharedImageFilterParams(),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.categories.getList',
            handlerClass: CategoriesGetListHandler::class,
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

        $service->addMethod(
            'pwg.getMissingDerivatives',
            $this->pwgCore->getMissingDerivatives(...),
            array_merge([
                'types' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'info' => 'square, thumb, 2small, xsmall, small, medium, large, xlarge, xxlarge, 3xlarge, 4xlarge',
                ],
                'ids' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'max_urls' => [
                    'default' => 200,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'prev_page' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ], $f_params),
            'Returns a list of derivatives to build.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.addComment',
            $this->pwgImages->addComment(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'author' => [
                    'default' => $this->accessControl->isAGuest() ? 'guest' : $this->currentUser->get()
                        ->username,
                ],
                'content' => [],
                'key' => [],
            ],
            'Adds a comment to an image.',
            options: [
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.getInfo',
            $this->pwgImages->getInfo(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'comments_page' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'comments_per_page' => [
                    'default' => $nb_comment_page,
                    'maxValue' => 2 * $nb_comment_page,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ],
            'Returns information about an image.'
        );

        $service->addMethod(
            'pwg.images.rate',
            $this->pwgImages->rate(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'rate' => [
                    'type' => WsParamType::FLOAT,
                ],
            ],
            'Rates an image.'
        );

        $service->addMethod(
            'pwg.images.search',
            $this->pwgImages->search(...),
            array_merge([
                'query' => [],
                'per_page' => [
                    'default' => 100,
                    'maxValue' => $this->currentConfig->wsMaxImagesPerPage,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'page' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'order' => [
                    'default' => null,
                    'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random',
                ],
            ], $f_params),
            'Returns elements for the corresponding query search.'
        );

        $service->addMethod(
            'pwg.images.setPrivacyLevel',
            $this->pwgImages->setPrivacyLevel(...),
            [
                'image_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'level' => [
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ],
            'Sets the privacy levels for the images.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.formats.searchImage',
            $this->pwgImages->formatsSearchImage(...),
            [
                'filename_list' => [],
            ],
            'Search for image ids matching the provided filenames. <b>filename_list</b> must be a JSON encoded associative array of unique_id:filename.<br><br>The method returns a list of unique_id:image_id.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.formats.delete',
            $this->pwgImages->formatsDelete(...),
            [
                'format_id' => [
                    'type' => WsParamType::ID,
                    'default' => null,
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'pwg_token' => [],
            ],
            'Remove a format',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.setRank',
            $this->pwgImages->setRank(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
                'rank' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL,
                    'default' => null,
                ],
            ],
            'Sets the rank of a photo for a given album.
    <br><br>If you provide a list for image_id:
    <ul>
    <li>rank becomes useless, only the order of the image_id list matters</li>
    <li>you are supposed to provide the list of all image_ids belonging to the album.
    </ul>',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.setCategory',
            $this->pwgImages->setCategory(...),
            [
                'image_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
                'action' => [
                    'default' => 'associate',
                    'info' => 'associate/dissociate/move',
                ],
                'pwg_token' => [],
            ],
            'Manage associations of images with an album. <b>action</b> can be:<ul><li><i>associate</i> : add photos to this album</li><li><i>dissociate</i> : remove photos from this album</li><li><i>move</i> : dissociate photos from any other album and adds photos to this album</li></ul>',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.rates.delete',
            $this->pwgCore->ratesDelete(...),
            [
                'user_id' => [
                    'type' => WsParamType::ID,
                ],
                'anonymous_id' => [
                    'default' => null,
                ],
                'image_id' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
            ],
            'Deletes all rates for a user.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.session.getStatus',
            $this->pwgCore->sessionGetStatus(...),
            null,
            'Gets information about the current session. Also provides a token useable with admin methods.'
        );

        $service->addMethod(
            'pwg.session.login',
            $this->pwgCore->sessionLogin(...),
            [
                'username' => [],
                'password' => [
                    'default' => null,
                ],
            ],
            'Tries to login the user.',
            options: [
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.session.logout',
            $this->pwgCore->sessionLogout(...),
            null,
            'Ends the current session.'
        );

        // pwg.tags.* (Group 19's fifth migrated domain) registers via
        // MethodDefinition/handlerClass -- Tags.php is gone, each method
        // is its own container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.tags.getList',
            handlerClass: TagsGetListHandler::class,
            description: 'Retrieves a list of available tags.',
            params: [
                ParamDefinition::optional('sort_by_counter', false, WsParamType::BOOL),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.getImages',
            handlerClass: TagsGetImagesHandler::class,
            description: 'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.',
            params: [
                ParamDefinition::optional('tag_id', null, WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_url_name', null, flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_name', null, flags: WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('tag_mode_and', false, WsParamType::BOOL),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', null, info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
                ...self::sharedImageFilterParams(),
            ],
        ));

        $service->addMethod(
            'pwg.images.addChunk',
            $this->pwgImages->addChunk(...),
            [
                'data' => [],
                'original_sum' => [],
                'type' => [
                    'default' => 'file',
                    'info' => 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.',
                ],
                'position' => [],
            ],
            'Add a chunk of a file.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.addFile',
            $this->pwgImages->addFile(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'type' => [
                    'default' => 'file',
                    'info' => 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.',
                ],
                'sum' => [],
            ],
            'Add or update a file for an existing photo.
    <br>pwg.images.addChunk must have been called before (maybe several times).',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.add',
            $this->pwgImages->add(...),
            [
                'thumbnail_sum' => [
                    'default' => null,
                ],
                'high_sum' => [
                    'default' => null,
                ],
                'original_sum' => [],
                'original_filename' => [
                    'default' => null,
                    'Provide it if "check_uniqueness" is true and the gallery\'s configured uniqueness mode is "filename".',
                ],
                'name' => [
                    'default' => null,
                ],
                'author' => [
                    'default' => null,
                ],
                'date_creation' => [
                    'default' => null,
                ],
                'comment' => [
                    'default' => null,
                ],
                'categories' => [
                    'default' => null,
                    'info' => 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.',
                ],
                'tag_ids' => [
                    'default' => null,
                    'info' => 'Comma separated ids',
                ],
                'level' => [
                    'default' => 0,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'check_uniqueness' => [
                    'default' => true,
                    'type' => WsParamType::BOOL,
                ],
                'image_id' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
            ],
            'Add an image.
    <br>pwg.images.addChunk must have been called before (maybe several times).
    <br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.addSimple',
            $this->pwgImages->addSimple(...),
            [
                'category' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'name' => [
                    'default' => null,
                ],
                'author' => [
                    'default' => null,
                ],
                'comment' => [
                    'default' => null,
                ],
                'level' => [
                    'default' => 0,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'tags' => [
                    'default' => null,
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'image_id' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
            ],
            'Add an image.
    <br>Use the <b>$_FILES[image]</b> field for uploading file.
    <br>Set the form encoding to "form-data".
    <br>You can update an existing photo if you define an existing image_id.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.upload',
            $this->pwgImages->upload(...),
            [
                'name' => [
                    'default' => null,
                ],
                'category' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'level' => [
                    'default' => 0,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'format_of' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                    'info' => 'id of the extended image (name/category/level are not used if format_of is provided)',
                ],
                'update_mode' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                    'info' => 'true if the update mode is active',
                ],
                'pwg_token' => [],
            ],
            'Add an image.
    <br>Use the <b>$_FILES[image]</b> field for uploading file.
    <br>Set the form encoding to "form-data".',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.uploadAsync',
            $this->pwgImages->uploadAsync(...),
            [
                'username' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'password' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'chunk' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'chunk_sum' => [],
                'chunks' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'original_sum' => [],
                'category' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'filename' => [],
                'name' => [
                    'default' => null,
                ],
                'author' => [
                    'default' => null,
                ],
                'comment' => [
                    'default' => null,
                ],
                'date_creation' => [
                    'default' => null,
                ],
                'level' => [
                    'default' => 0,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'tag_ids' => [
                    'default' => null,
                    'info' => 'Comma separated ids',
                ],
                'image_id' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
            ],
            'Upload photo by chunks in a random order.
    <br>Use the <b>$_FILES[file]</b> field for uploading file.
    <br>Start with chunk 0 (zero).
    <br>Set the form encoding to "form-data".
    <br>You can update an existing photo if you define an existing image_id.
    <br>Requires <b>admin</b> credentials: either with username/password or header authorization with api key.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.delete',
            $this->pwgImages->delete(...),
            [
                'image_id' => [
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'pwg_token' => [],
            ],
            'Deletes image(s).',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.setMd5sum',
            $this->pwgImages->setMd5sum(...),
            [
                'block_size' => [
                    'default' => $this->currentConfig->checksumComputeBlocksize,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'pwg_token' => [],
            ],
            'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.syncMetadata',
            $this->pwgImages->syncMetadata(...),
            [
                'image_id' => [
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                    'info' => 'Comma separated ids or array of id',
                ],
                'pwg_token' => [],
            ],
            'Sync metadatas, by blocks. Returns how many images were synchronized',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.deleteOrphans',
            $this->pwgImages->deleteOrphans(...),
            [
                'block_size' => [
                    'default' => 1000,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'pwg_token' => [],
            ],
            'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->register(new MethodDefinition(
            name: 'pwg.categories.calculateOrphans',
            handlerClass: CalculateOrphansHandler::class,
            description: 'Return the number of orphan photos if an album is deleted.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.categories.getAdminList',
            handlerClass: CategoriesGetAdminListHandler::class,
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

        $service->register(new MethodDefinition(
            name: 'pwg.categories.add',
            handlerClass: CategoriesAddHandler::class,
            description: 'Adds an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
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
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.categories.delete',
            handlerClass: CategoriesDeleteHandler::class,
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

        $service->register(new MethodDefinition(
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

        $service->register(new MethodDefinition(
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

        $service->register(new MethodDefinition(
            name: 'pwg.categories.deleteRepresentative',
            handlerClass: DeleteRepresentativeHandler::class,
            description: 'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.categories.refreshRepresentative',
            handlerClass: RefreshRepresentativeHandler::class,
            description: 'Find a new album thumbnail.',
            params: [
                ParamDefinition::required('category_id', WsParamType::ID),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.getAdminList',
            handlerClass: TagsGetAdminListHandler::class,
            description: '<b>Admin only.</b>',
            requiresAuth: true,
        ));

        // Known limitation: one tag per call -- batch creation would need
        // a param-shape change (a list instead of a single 'name'),
        // deliberate current API shape, not a defect.
        $service->register(new MethodDefinition(
            name: 'pwg.tags.add',
            handlerClass: TagsAddHandler::class,
            description: 'Adds a new tag.',
            params: [
                ParamDefinition::required('name'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.delete',
            handlerClass: TagsDeleteHandler::class,
            description: 'Delete tag(s) by ID.',
            params: [
                ParamDefinition::required('tag_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.tags.rename',
            handlerClass: TagsRenameHandler::class,
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
            handlerClass: TagsDuplicateHandler::class,
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
            handlerClass: TagsMergeHandler::class,
            description: 'Merge tags in one other group',
            params: [
                ParamDefinition::required('destination_tag_id', WsParamType::ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required('merge_tag_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->addMethod(
            'pwg.images.exist',
            $this->pwgImages->exist(...),
            [
                'md5sum_list' => [
                    'default' => null,
                ],
                'filename_list' => [
                    'default' => null,
                ],
            ],
            'Checks existence of images.
    <br>Give <b>md5sum_list</b> if $conf[uniqueness_mode]==md5sum. Give <b>filename_list</b> if $conf[uniqueness_mode]==filename.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.checkFiles',
            $this->pwgImages->checkFiles(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'file_sum' => [
                    'default' => null,
                ],
                'thumbnail_sum' => [
                    'default' => null,
                ],
                'high_sum' => [
                    'default' => null,
                ],
            ],
            'Checks if you have updated version of your files for a given photo, the answer can be "missing", "equals" or "differs".
    <br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.checkUpload',
            $this->pwgImages->checkUpload(...),
            null,
            'Checks if Piwigo is ready for upload.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.emptyLounge',
            $this->pwgImages->emptyLounge(...),
            null,
            'Empty lounge, where images may be waiting before taking off.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.uploadCompleted',
            $this->pwgImages->uploadCompleted(...),
            [
                'image_id' => [
                    'default' => null,
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'pwg_token' => [],
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.setInfo',
            $this->pwgImages->setInfo(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'file' => [
                    'default' => null,
                ],
                'name' => [
                    'default' => null,
                ],
                'author' => [
                    'default' => null,
                ],
                'date_creation' => [
                    'default' => null,
                ],
                'comment' => [
                    'default' => null,
                ],
                'categories' => [
                    'default' => null,
                    'info' => 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.',
                ],
                'tag_ids' => [
                    'default' => null,
                    'info' => 'Comma separated ids',
                ],
                'level' => [
                    'default' => null,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'single_value_mode' => [
                    'default' => 'fill_if_empty',
                ],
                'multiple_value_mode' => [
                    'default' => 'append',
                ],
                'pwg_token' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
            ],
            'Changes properties of an image.
    <br><b>single_value_mode</b> can be "fill_if_empty" (only use the input value if the corresponding values is currently empty) or "replace"
    (overwrite any existing value) and applies to single values properties like name/author/date_creation/comment.
    <br><b>multiple_value_mode</b> can be "append" (no change on existing values, add the new values) or "replace" and applies to multiple values properties like tag_ids/categories.
    <br><b>pwg_token</b> required if you want to use HTML in name/comment/author.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->register(new MethodDefinition(
            name: 'pwg.categories.setInfo',
            handlerClass: CategoriesSetInfoHandler::class,
            description: 'Changes properties of an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
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

        $service->register(new MethodDefinition(
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

        // pwg.plugins.*/pwg.themes.performAction/pwg.extensions.* (Group 19's
        // third migrated domain) register via MethodDefinition/handlerClass
        // -- Extensions.php is gone, each method is its own
        // container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.plugins.getList',
            handlerClass: PluginsGetListHandler::class,
            description: 'Gets the list of plugins with id, name, version, state and description.',
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.plugins.performAction',
            handlerClass: PluginsPerformActionHandler::class,
            params: [
                ParamDefinition::required('action', info: 'install, activate, deactivate, uninstall, delete'),
                ParamDefinition::required('plugin'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.themes.performAction',
            handlerClass: ThemesPerformActionHandler::class,
            params: [
                ParamDefinition::required('action', info: 'activate, deactivate, delete, set_default'),
                ParamDefinition::required('theme'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.update',
            handlerClass: UpdateHandler::class,
            description: '<b>Webmaster only.</b>',
            params: [
                ParamDefinition::required('type', info: 'plugins, languages, themes'),
                ParamDefinition::required('id'),
                ParamDefinition::required('revision'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.ignoreUpdate',
            handlerClass: IgnoreUpdateHandler::class,
            description: '<b>Webmaster only.</b> Ignores an extension if it needs update.',
            params: [
                ParamDefinition::optional('type', info: 'plugins, languages, themes'),
                ParamDefinition::optional('id'),
                ParamDefinition::optional('reset', false, WsParamType::BOOL, info: 'If true, all ignored extensions will be reinitilized.'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.extensions.checkUpdates',
            handlerClass: CheckUpdatesHandler::class,
            description: 'Checks if piwigo or extensions are up to date.',
            requiresAuth: true,
        ));

        // pwg.groups.* (Group 19's fourth migrated domain) registers via
        // MethodDefinition/handlerClass -- Groups.php is gone, each method
        // is its own container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.groups.getList',
            handlerClass: GroupsGetListHandler::class,
            description: 'Retrieves a list of all groups. The list can be filtered.',
            params: [
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('name', info: 'Use "%" as wildcard.'),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxUsersPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', 'name', info: 'id, name, nb_users, is_default'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.add',
            handlerClass: GroupsAddHandler::class,
            description: 'Creates a group and returns the new group record.',
            params: [
                ParamDefinition::required('name'),
                ParamDefinition::optional('is_default', false, WsParamType::BOOL),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.delete',
            handlerClass: GroupsDeleteHandler::class,
            description: 'Deletes a or more groups. Users and photos are not deleted.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.setInfo',
            handlerClass: GroupsSetInfoHandler::class,
            description: 'Updates a group. Leave a field blank to keep the current value.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::optionalFlag('name'),
                ParamDefinition::optionalFlag('is_default', WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.addUser',
            handlerClass: GroupsAddUserHandler::class,
            description: 'Adds one or more users to a group.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.deleteUser',
            handlerClass: GroupsDeleteUserHandler::class,
            description: 'Removes one or more users from a group.',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.merge',
            handlerClass: GroupsMergeHandler::class,
            description: 'Merge groups in one other group',
            params: [
                ParamDefinition::required('destination_group_id', WsParamType::ID, info: 'Is not necessarily part of groups to merge'),
                ParamDefinition::required('merge_group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.groups.duplicate',
            handlerClass: GroupsDuplicateHandler::class,
            description: 'Create a copy of a group',
            params: [
                ParamDefinition::required('group_id', WsParamType::ID),
                ParamDefinition::required('copy_name'),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.getList',
            handlerClass: UsersGetListHandler::class,
            description: 'Retrieves a list of all the users.<br>
    <br>
    <b>display</b> controls which data are returned, possible values are:<br>
    all, basics, none,<br>
    username, email, status, level, groups,<br>
    language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits,<br>
    enabled_high, registration_date, registration_date_string, registration_date_since, last_visit, last_visit_string, last_visit_since<br>
    <b>basics</b> stands for "username,email,status,level,groups"<br>
    <b>min_register</b> and <b>max_register</b> filter users by their registration date expecting format "YYYY" or "YYYY-mm" or "YYYY-mm-dd".',
            params: [
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('username', info: 'Use "%" as wildcard.'),
                ParamDefinition::optionalFlag('status', flags: WsParamFlag::FORCE_ARRAY, info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optional('min_level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max($available_permission_levels)),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxUsersPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', 'id', info: 'id, username, level, email'),
                ParamDefinition::optionalFlag('exclude', WsParamType::ID, WsParamFlag::FORCE_ARRAY, info: 'Expects a user_id as value.'),
                ParamDefinition::optional('display', 'basics', info: 'Comma saparated list (see method description)'),
                ParamDefinition::optionalFlag('filter', info: 'Filter by username, email, group'),
                ParamDefinition::optionalFlag('min_register', info: 'See method description'),
                ParamDefinition::optionalFlag('max_register', info: 'See method description'),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.add',
            handlerClass: UsersAddHandler::class,
            description: 'Registers a new user.',
            params: [
                ParamDefinition::required('username'),
                ParamDefinition::optional('auto_password', false, WsParamType::BOOL, info: 'if true ignores password and confirm password'),
                ParamDefinition::optional('password'),
                ParamDefinition::optionalFlag('password_confirm'),
                ParamDefinition::optional('email'),
                ParamDefinition::optional('send_password_by_mail', false, WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.delete',
            handlerClass: UsersDeleteHandler::class,
            description: 'Deletes on or more users. Photos owned by this user are not deleted.',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.getAuthKey',
            handlerClass: GetAuthKeyHandler::class,
            description: 'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.setInfo',
            handlerClass: UsersSetInfoHandler::class,
            description: 'Updates a user. Leave a field blank to keep the current value.
    <br>"username", "password" and "email" are ignored if "user_id" is an array.
    <br>set "group_id" to -1 if you want to dissociate users from all groups',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag('status', info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optionalFlag('level', WsParamType::INT | WsParamType::POSITIVE, maxValue: max($available_permission_levels)),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('group_id', WsParamType::INT, WsParamFlag::FORCE_ARRAY),
                // bellow are parameters removed in a future version
                ParamDefinition::optionalFlag('nb_image_page', WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
                ParamDefinition::optionalFlag('recent_period', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('expand', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_comments', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_hits', WsParamType::BOOL),
                ParamDefinition::optionalFlag('enabled_high', WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.setMyInfo',
            handlerClass: SetMyInfoHandler::class,
            params: [
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag('nb_image_page', WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('recent_period', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('expand', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_comments', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_hits', WsParamType::BOOL),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('new_password'),
                ParamDefinition::optionalFlag('conf_new_password'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        // pwg.permissions.* (Group 19's second migrated domain) registers
        // via MethodDefinition/handlerClass -- Permissions.php is gone,
        // each method is its own container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.permissions.getList',
            handlerClass: PermissionsGetListHandler::class,
            description: 'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
    <br>Provide only one parameter!',
            params: [
                ParamDefinition::optionalFlag('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
            ],
            requiresAuth: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.permissions.add',
            handlerClass: PermissionsAddHandler::class,
            description: 'Adds permissions to an album.',
            params: [
                ParamDefinition::required('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('recursive', false, WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.permissions.remove',
            handlerClass: PermissionsRemoveHandler::class,
            description: 'Removes permissions from an album.',
            params: [
                ParamDefinition::required('cat_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.preferences.set',
            handlerClass: PreferencesSetHandler::class,
            description: 'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.',
            params: [
                ParamDefinition::required('param'),
                ParamDefinition::optionalFlag('value'),
                ParamDefinition::optional('is_json', false, WsParamType::BOOL),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.favorites.add',
            handlerClass: FavoritesAddHandler::class,
            description: 'Adds the indicated image to the current user\'s favorite images.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.favorites.remove',
            handlerClass: FavoritesRemoveHandler::class,
            description: 'Removes the indicated image from the current user\'s favorite images.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
            ],
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.favorites.getList',
            handlerClass: FavoritesGetListHandler::class,
            description: 'Returns the favorite images of the current user.',
            params: [
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
            ],
        ));

        $service->addMethod(
            'pwg.history.log',
            $this->pwgCore->historyLog(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
                'cat_id' => [
                    'type' => WsParamType::ID,
                    'default' => null,
                ],
                'section' => [
                    'default' => null,
                ],
                'tags_string' => [
                    'default' => null,
                ],
                'is_download' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
            ],
            'Log visit in history'
        );

        $service->addMethod(
            'pwg.history.search',
            $this->pwgCore->historySearch(...),
            [
                'start' => [
                    'default' => null,
                ],
                'end' => [
                    'default' => null,
                ],
                'types' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'default' => [
                        'none',
                        'picture',
                        'high',
                        'other',
                    ],
                ],
                'user_id' => [
                    'default' => -1,
                ],
                'image_id' => [
                    'default' => null,
                    'type' => WsParamType::ID,
                ],
                'filename' => [
                    'default' => null,
                ],
                'ip' => [
                    'default' => null,
                ],
                'display_thumbnail' => [
                    'default' => 'display_thumbnail_classic',
                ],
                'pageNumber' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ],
            'Gives an history of who has visited the galery and the actions done in it. Receives parameter.
          <br> <strong>Types </strong> can be : \'none\', \'picture\', \'high\', \'other\'
          <br> <strong>Date format</strong> is yyyy-mm-dd
          <br> <strong>display_thumbnail</strong> can be : \'no_display_thumbnail\', \'display_thumbnail_classic\', \'display_thumbnail_hoverbox\'',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.images.filteredSearch.create',
            $this->pwgImages->filteredSearchCreate(...),
            [
                'search_id' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'prior search_id (or search_key), if any',
                ],
                'allwords' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'query to search by words',
                ],
                'allwords_mode' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'AND (by default) | OR',
                ],
                'allwords_fields' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'info' => 'values among [name, comment, tags, file, author, cat-title, cat-desc]',
                ],
                'tags' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'tags_mode' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'AND (by default) | OR',
                ],
                'categories' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'categories_withsubs' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                    'info' => 'false, by default',
                ],
                'authors' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                ],
                'added_by' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'filetypes' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                ],
                'date_posted_preset' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'files posted within 24 hours, 7 days, 30 days, 3 months, 6 months or custom. Value among 24h|7d|30d|3m|6m|custom.',
                ],
                'date_posted_custom' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'info' => 'Must be provided if date_posted_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.',
                ],
                'date_created_preset' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'files created within 7 days, 30 days, 3 months, 6 months, 12 months or custom. Value among 7d|30d|3m|6m|12m|custom.',
                ],
                'date_created_custom' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'info' => 'Must be provided if date_created_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.',
                ],
                'ratios' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                ],
                'ratings' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                ],
                'filesize_min' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'filesize_max' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'height_min' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'height_max' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'width_min' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'width_max' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ],
            ''
        );

        $service->register(new MethodDefinition(
            name: 'pwg.users.generatePasswordLink',
            handlerClass: GeneratePasswordLinkHandler::class,
            description: 'Return the reset password link <br />
           (Only webmaster can perform this action for another webmaster)',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::optional('send_by_mail', false, WsParamType::BOOL),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.setMainUser',
            handlerClass: SetMainUserHandler::class,
            description: 'Update the main user (owner) <br />
            - To be the main user, the user must have the status "webmaster".<br />
            - Only a webmaster can perform this action',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.api_key.create',
            handlerClass: CreateApiKeyHandler::class,
            description: 'Create a new api key for the user in the current session',
            params: [
                ParamDefinition::required('key_name'),
                ParamDefinition::required('duration', WsParamType::INT | WsParamType::POSITIVE, info: 'Number of days'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.api_key.revoke',
            handlerClass: RevokeApiKeyHandler::class,
            description: 'Revoke a api key for the user in the current session',
            params: [
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.api_key.edit',
            handlerClass: EditApiKeyHandler::class,
            description: 'Edit a api key for the user in the current session',
            params: [
                ParamDefinition::required('key_name'),
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(new MethodDefinition(
            name: 'pwg.users.api_key.get',
            handlerClass: GetApiKeyHandler::class,
            description: 'Get all api key for the user in the current session',
            params: [
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        // pwg.userComments.* (Group 19's first migrated domain) registers
        // via MethodDefinition/handlerClass -- Comments.php is gone, each
        // method is its own container-resolved WsAction.
        $service->register(new MethodDefinition(
            name: 'pwg.userComments.getList',
            handlerClass: CommentsGetListHandler::class,
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
            handlerClass: CommentsDeleteHandler::class,
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
            handlerClass: CommentsValidateHandler::class,
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
