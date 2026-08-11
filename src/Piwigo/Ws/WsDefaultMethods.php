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
use Piwigo\Ws\Event\WsAddMethods;

final class WsDefaultMethods
{
    // Each Pwg* class used by register() is injected as a constructor
    // property; the property list here and the instance-method callbacks
    // registered below must stay in sync -- register() calls these as real
    // instance methods (e.g. $this->pwgCore->getVersion(...)), not static
    // ClassName::method() calls.
    public function __construct(
        private readonly PwgCategories $pwgCategories,
        private readonly PwgCore $pwgCore,
        private readonly PwgPermissions $pwgPermissions,
        private readonly PwgComments $pwgComments,
        private readonly PwgExtensions $pwgExtensions,
        private readonly PwgGroups $pwgGroups,
        private readonly PwgTags $pwgTags,
        private readonly PwgUsers $pwgUsers,
        private readonly PwgImages $pwgImages,
        private readonly CurrentConfig $currentConfig,
        private readonly AccessControl $accessControl,
        private readonly CurrentUser $currentUser,
        private readonly ImageStdParams $imageStdParams,
    ) {}

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

        $service->addMethod(
            'pwg.categories.getImages',
            $this->pwgCategories->getImages(...),
            array_merge([
                'cat_id' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'recursive' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
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
            'Returns elements for the corresponding categories.
    <br><b>cat_id</b> can be empty if <b>recursive</b> is true.
    <br><b>order</b> comma separated fields for sorting'
        );

        $service->addMethod(
            'pwg.categories.getList',
            $this->pwgCategories->getList(...),
            [
                'cat_id' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                    'info' => 'Parent category. "0" or empty for root.',
                ],
                'recursive' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'public' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'tree_output' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'fullname' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'thumbnail_size' => [
                    'default' => ImageStdParams::THUMB,
                    'info' => implode(',', array_keys($this->imageStdParams->getDefinedTypeMap())),
                ],
                'search' => [
                    'default' => null,
                ],
                'limit' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                    'info' => 'Parameter not compatible with recursive=true',
                ],
            ],
            'Returns a list of categories.'
        );

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

        $service->addMethod(
            'pwg.tags.getList',
            $this->pwgTags->getList(...),
            [
                'sort_by_counter' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
            ],
            'Retrieves a list of available tags.'
        );

        $service->addMethod(
            'pwg.tags.getImages',
            $this->pwgTags->getImages(...),
            array_merge([
                'tag_id' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'tag_url_name' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
                'tag_name' => [
                    'default' => null,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
                'tag_mode_and' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
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
            'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.'
        );

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

        $service->addMethod(
            'pwg.categories.calculateOrphans',
            $this->pwgCategories->calculateOrphans(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
            ],
            'Return the number of orphan photos if an album is deleted.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.getAdminList',
            $this->pwgCategories->getAdminList(...),
            [
                'cat_id' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                    'info' => 'Parent category. "0" or empty for root.',
                ],
                'search' => [
                    'default' => null,
                ],
                'recursive' => [
                    'default' => true,
                    'type' => WsParamType::BOOL,
                ],
                'additional_output' => [
                    'default' => null,
                    'info' => 'Comma saparated list (see method description)',
                ],
            ],
            'Get albums list as displayed on admin page. <br>
          <b>additional_output</b> controls which data are returned, possible values are:<br>
          null, full_name_with_admin_links<br>',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.add',
            $this->pwgCategories->add(...),
            [
                'name' => [],
                'parent' => [
                    'default' => null,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'comment' => [
                    'default' => null,
                ],
                'visible' => [
                    'default' => true,
                    'type' => WsParamType::BOOL,
                ],
                'status' => [
                    'default' => null,
                    'info' => 'public, private',
                ],
                'commentable' => [
                    'default' => true,
                    'type' => WsParamType::BOOL,
                ],
                'position' => [
                    'default' => null,
                    'info' => 'first, last',
                ],
                'pwg_token' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
            ],
            'Adds an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.delete',
            $this->pwgCategories->delete(...),
            [
                'category_id' => [
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'photo_deletion_mode' => [
                    'default' => 'delete_orphans',
                ],
                'pwg_token' => [],
            ],
            'Deletes album(s).
    <br><b>photo_deletion_mode</b> can be "no_delete" (may create orphan photos), "delete_orphans"
    (default mode, only deletes photos linked to no other album) or "force_delete" (delete all photos, even those linked to other albums)',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.move',
            $this->pwgCategories->move(...),
            [
                'category_id' => [
                    'flags' => WsParamFlag::ACCEPT_ARRAY,
                ],
                'parent' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'pwg_token' => [],
            ],
            'Move album(s).
    <br>Set parent as 0 to move to gallery root. Only virtual categories can be moved.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.setRepresentative',
            $this->pwgCategories->setRepresentative(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Sets the representative photo for an album. The photo doesn\'t have to belong to the album.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.deleteRepresentative',
            $this->pwgCategories->deleteRepresentative(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.refreshRepresentative',
            $this->pwgCategories->refreshRepresentative(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Find a new album thumbnail.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.tags.getAdminList',
            $this->pwgTags->getAdminList(...),
            null,
            '<b>Admin only.</b>',
            options: [
                'admin_only' => true,
            ]
        );

        // Known limitation: one tag per call -- batch creation would need
        // a param-shape change (a list instead of a single 'name'),
        // deliberate current API shape, not a defect.
        $service->addMethod(
            'pwg.tags.add',
            $this->pwgTags->add(...),
            [
                'name' => [],
            ],
            'Adds a new tag.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.tags.delete',
            $this->pwgTags->delete(...),
            [
                'tag_id' => [
                    'type' => WsParamType::ID,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
                'pwg_token' => [],
            ],
            'Delete tag(s) by ID.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.tags.rename',
            $this->pwgTags->rename(...),
            [
                'tag_id' => [
                    'type' => WsParamType::ID,
                ],
                'new_name' => [],
                'pwg_token' => [],
            ],
            'Rename tag',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.tags.duplicate',
            $this->pwgTags->duplicate(...),
            [
                'tag_id' => [
                    'type' => WsParamType::ID,
                ],
                'copy_name' => [],
                'pwg_token' => [],
            ],
            'Create a copy of a tag',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.tags.merge',
            $this->pwgTags->merge(...),
            [
                'destination_tag_id' => [
                    'type' => WsParamType::ID,
                    'info' => 'Is not necessarily part of groups to merge',
                ],
                'merge_tag_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Merge tags in one other group',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

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

        $service->addMethod(
            'pwg.categories.setInfo',
            $this->pwgCategories->setInfo(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                ],
                'name' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'comment' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'status' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'public, private',
                ],
                'visible' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'commentable' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'Boolean, effective if configuration variable activate_comments is set to true',
                ],
                'apply_commentable_to_subalbums' => [
                    'default' => null,
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'If true, set commentable to all sub album',
                ],
                'pwg_token' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
            ],
            'Changes properties of an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.categories.setRank',
            $this->pwgCategories->setRank(...),
            [
                'category_id' => [
                    'type' => WsParamType::ID,
                    'flags' => WsParamFlag::FORCE_ARRAY,
                ],
                'rank' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL,
                    'flags' => WsParamFlag::OPTIONAL,
                ],
            ],
            'Changes the rank of an album
            <br><br>If you provide a list for category_id:
            <ul>
            <li>rank becomes useless, only the order of the image_id list matters</li>
            <li>you are supposed to provide the list of all categories_ids belonging to the album.
            </ul>.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.plugins.getList',
            $this->pwgExtensions->pluginsGetList(...),
            null,
            'Gets the list of plugins with id, name, version, state and description.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.plugins.performAction',
            $this->pwgExtensions->pluginsPerformAction(...),
            [
                'action' => [
                    'info' => 'install, activate, deactivate, uninstall, delete',
                ],
                'plugin' => [],
                'pwg_token' => [],
            ],
            null,
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.themes.performAction',
            $this->pwgExtensions->themesPerformAction(...),
            [
                'action' => [
                    'info' => 'activate, deactivate, delete, set_default',
                ],
                'theme' => [],
                'pwg_token' => [],
            ],
            null,
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.extensions.update',
            $this->pwgExtensions->update(...),
            [
                'type' => [
                    'info' => 'plugins, languages, themes',
                ],
                'id' => [],
                'revision' => [],
                'pwg_token' => [],
            ],
            '<b>Webmaster only.</b>',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.extensions.ignoreUpdate',
            $this->pwgExtensions->ignoreUpdate(...),
            [
                'type' => [
                    'default' => null,
                    'info' => 'plugins, languages, themes',
                ],
                'id' => [
                    'default' => null,
                ],
                'reset' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                    'info' => 'If true, all ignored extensions will be reinitilized.',
                ],
                'pwg_token' => [],
            ],
            '<b>Webmaster only.</b> Ignores an extension if it needs update.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.extensions.checkUpdates',
            $this->pwgExtensions->checkUpdates(...),
            null,
            'Checks if piwigo or extensions are up to date.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.getList',
            $this->pwgGroups->getList(...),
            [
                'group_id' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'name' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'Use "%" as wildcard.',
                ],
                'per_page' => [
                    'default' => 100,
                    'maxValue' => $this->currentConfig->wsMaxUsersPerPage,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'page' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'order' => [
                    'default' => 'name',
                    'info' => 'id, name, nb_users, is_default',
                ],
            ],
            'Retrieves a list of all groups. The list can be filtered.',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.add',
            $this->pwgGroups->add(...),
            [
                'name' => [],
                'is_default' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
            ],
            'Creates a group and returns the new group record.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.delete',
            $this->pwgGroups->delete(...),
            [
                'group_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Deletes a or more groups. Users and photos are not deleted.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.setInfo',
            $this->pwgGroups->setInfo(...),
            [
                'group_id' => [
                    'type' => WsParamType::ID,
                ],
                'name' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'is_default' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'pwg_token' => [],
            ],
            'Updates a group. Leave a field blank to keep the current value.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.addUser',
            $this->pwgGroups->addUser(...),
            [
                'group_id' => [
                    'type' => WsParamType::ID,
                ],
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Adds one or more users to a group.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.deleteUser',
            $this->pwgGroups->deleteUser(...),
            [
                'group_id' => [
                    'type' => WsParamType::ID,
                ],
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Removes one or more users from a group.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.merge',
            $this->pwgGroups->merge(...),
            [
                'destination_group_id' => [
                    'type' => WsParamType::ID,
                    'info' => 'Is not necessarily part of groups to merge',
                ],
                'merge_group_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Merge groups in one other group',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.groups.duplicate',
            $this->pwgGroups->duplicate(...),
            [
                'group_id' => [
                    'type' => WsParamType::ID,
                ],
                'copy_name' => [],
                'pwg_token' => [],
            ],
            'Create a copy of a group',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.getList',
            $this->pwgUsers->getList(...),
            [
                'user_id' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'username' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'Use "%" as wildcard.',
                ],
                'status' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'info' => 'guest,generic,normal,admin,webmaster',
                ],
                'min_level' => [
                    'default' => 0,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'group_id' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'per_page' => [
                    'default' => 100,
                    'maxValue' => $this->currentConfig->wsMaxUsersPerPage,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'page' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'order' => [
                    'default' => 'id',
                    'info' => 'id, username, level, email',
                ],
                'exclude' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                    'info' => 'Expects a user_id as value.',
                ],
                'display' => [
                    'default' => 'basics',
                    'info' => 'Comma saparated list (see method description)',
                ],
                'filter' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'Filter by username, email, group',
                ],
                'min_register' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'See method description',
                ],
                'max_register' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'See method description',
                ],
            ],
            'Retrieves a list of all the users.<br>
    <br>
    <b>display</b> controls which data are returned, possible values are:<br>
    all, basics, none,<br>
    username, email, status, level, groups,<br>
    language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits,<br>
    enabled_high, registration_date, registration_date_string, registration_date_since, last_visit, last_visit_string, last_visit_since<br>
    <b>basics</b> stands for "username,email,status,level,groups"<br>
    <b>min_register</b> and <b>max_register</b> filter users by their registration date expecting format "YYYY" or "YYYY-mm" or "YYYY-mm-dd".',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.add',
            $this->pwgUsers->add(...),
            [
                'username' => [],
                'auto_password' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                    'info' => 'if true ignores password and confirm password',
                ],
                'password' => [
                    'default' => null,
                ],
                'password_confirm' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'email' => [
                    'default' => null,
                ],
                'send_password_by_mail' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'pwg_token' => [],
            ],
            'Registers a new user.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.delete',
            $this->pwgUsers->delete(...),
            [
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Deletes on or more users. Photos owned by this user are not deleted.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.getAuthKey',
            $this->pwgUsers->getAuthKey(...),
            [
                'user_id' => [
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.setInfo',
            $this->pwgUsers->setInfo(...),
            [
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'username' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'password' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'email' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'status' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'info' => 'guest,generic,normal,admin,webmaster',
                ],
                'level' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'maxValue' => max($available_permission_levels),
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'language' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'theme' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'group_id' => [
                    'flags' => WsParamFlag::OPTIONAL | WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::INT,
                ],
                // bellow are parameters removed in a future version
                'nb_image_page' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL,
                ],
                'recent_period' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'expand' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'show_nb_comments' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'show_nb_hits' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'enabled_high' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'pwg_token' => [],
            ],
            'Updates a user. Leave a field blank to keep the current value.
    <br>"username", "password" and "email" are ignored if "user_id" is an array.
    <br>set "group_id" to -1 if you want to dissociate users from all groups',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.setMyInfo',
            $this->pwgUsers->setMyInfo(...),
            [
                'email' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'nb_image_page' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL,
                ],
                'theme' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'language' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'recent_period' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'expand' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'show_nb_comments' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'show_nb_hits' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                ],
                'password' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'new_password' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'conf_new_password' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'pwg_token' => [],
            ],
            '',
            options: [
                'admin_only' => false,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.permissions.getList',
            $this->pwgPermissions->getList(...),
            [
                'cat_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'group_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
            ],
            'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
    <br>Provide only one parameter!',
            options: [
                'admin_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.permissions.add',
            $this->pwgPermissions->add(...),
            [
                'cat_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'group_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'recursive' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
                'pwg_token' => [],
            ],
            'Adds permissions to an album.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.permissions.remove',
            $this->pwgPermissions->remove(...),
            [
                'cat_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::ID,
                ],
                'group_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'user_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY | WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Removes permissions from an album.',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.preferences.set',
            $this->pwgUsers->preferencesSet(...),
            [
                'param' => [],
                'value' => [
                    'flags' => WsParamFlag::OPTIONAL,
                ],
                'is_json' => [
                    'default' => false,
                    'type' => WsParamType::BOOL,
                ],
            ],
            'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.'
        );

        $service->addMethod(
            'pwg.users.favorites.add',
            $this->pwgUsers->favoritesAdd(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Adds the indicated image to the current user\'s favorite images.'
        );

        $service->addMethod(
            'pwg.users.favorites.remove',
            $this->pwgUsers->favoritesRemove(...),
            [
                'image_id' => [
                    'type' => WsParamType::ID,
                ],
            ],
            'Removes the indicated image from the current user\'s favorite images.'
        );

        $service->addMethod(
            'pwg.users.favorites.getList',
            $this->pwgUsers->favoritesGetList(...),
            [
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
            ],
            'Returns the favorite images of the current user.'
        );

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

        $service->addMethod(
            'pwg.users.generatePasswordLink',
            $this->pwgUsers->generatePasswordLink(...),
            [
                'user_id' => [
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
                'send_by_mail' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::BOOL,
                    'default' => false,
                ],
            ],
            'Return the reset password link <br />
           (Only webmaster can perform this action for another webmaster)',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.setMainUser',
            $this->pwgUsers->setMainUser(...),
            [
                'user_id' => [
                    'type' => WsParamType::ID,
                ],
                'pwg_token' => [],
            ],
            'Update the main user (owner) <br />
            - To be the main user, the user must have the status "webmaster".<br />
            - Only a webmaster can perform this action',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.api_key.create',
            $this->pwgUsers->createApiKey(...),
            [
                'key_name' => [],
                'duration' => [
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                    'info' => 'Number of days',
                ],
                'pwg_token' => [],
            ],
            'Create a new api key for the user in the current session',
            options: [
                'admin_only' => false,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.api_key.revoke',
            $this->pwgUsers->revokeApiKey(...),
            [
                'pkid' => [],
                'pwg_token' => [],
            ],
            'Revoke a api key for the user in the current session',
            options: [
                'admin_only' => false,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.api_key.edit',
            $this->pwgUsers->editApiKey(...),
            [
                'key_name' => [],
                'pkid' => [],
                'pwg_token' => [],
            ],
            'Edit a api key for the user in the current session',
            options: [
                'admin_only' => false,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.users.api_key.get',
            $this->pwgUsers->getApiKey(...),
            [
                'pwg_token' => [],
            ],
            'Get all api key for the user in the current session',
            options: [
                'admin_only' => false,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.userComments.getList',
            $this->pwgComments->getList(...),
            [
                'status' => [
                    'default' => 'all',
                    'info' => 'must be: all, validated or pending',
                ],
                'search' => [
                    'default' => null,
                    'info' => 'All other parameters are not used during a search.',
                ],
                'author_id' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'image_id' => [
                    'flags' => WsParamFlag::OPTIONAL,
                    'type' => WsParamType::ID,
                ],
                'f_min_date' => [
                    'default' => null,
                ],
                'f_max_date' => [
                    'default' => null,
                ],
                'page' => [
                    'default' => 0,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'per_page' => [
                    'default' => $this->currentConfig->commentsPageNbComments,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
            ],
            'Get comments',
            options: [
                'admin_only' => true,
                'post_only' => false,
            ]
        );

        $service->addMethod(
            'pwg.userComments.delete',
            $this->pwgComments->delete(...),
            [
                'comment_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'pwg_token' => [],
            ],
            'Delete comments',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );

        $service->addMethod(
            'pwg.userComments.validate',
            $this->pwgComments->validate(...),
            [
                'comment_id' => [
                    'flags' => WsParamFlag::FORCE_ARRAY,
                    'type' => WsParamType::INT | WsParamType::POSITIVE,
                ],
                'pwg_token' => [],
            ],
            'Validate comments',
            options: [
                'admin_only' => true,
                'post_only' => true,
            ]
        );
    }
}
