<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
define('IN_WS', true);

include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap global, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

check_status(ACCESS_FREE);

if (! $conf['allow_web_services']) {
    page_forbidden('Web services are disabled');
}

include_once PHPWG_ROOT_PATH . 'include/ws_init.inc.php';

// Set by include/ws_init.inc.php, right above.
/** @var PwgServer $service */
global $service;

$service->run();

/**
 * event handler that registers standard methods with the web service
 *
 * @param array<int, PwgServer> $arr
 */
function ws_addDefaultMethods(array $arr): void
{
    /** @var array<string, mixed> $conf */
    global $conf;
    /** @var array<string, mixed> $user */
    global $user;
    $service = &$arr[0];

    include_once PHPWG_ROOT_PATH . 'include/ws_functions.inc.php';
    $ws_functions_root = PHPWG_ROOT_PATH . 'include/ws_functions/';

    // $conf['available_permission_levels'] defaults to [0, 1, 2, 4, 8] (see
    // include/config_default.inc.php); guard against a misconfigured/empty value
    // since max() requires a non-empty array.
    $available_permission_levels = $conf['available_permission_levels'];
    $available_permission_levels = is_array($available_permission_levels) && $available_permission_levels !== []
        ? $available_permission_levels
        : [0, 1, 2, 4, 8];

    // $conf['nb_comment_page'] is a numeric config value (see admin/configuration.php).
    $nb_comment_page = $conf['nb_comment_page'];
    $nb_comment_page = is_numeric($nb_comment_page) ? (int) $nb_comment_page : 0;

    $f_params = [
        'f_min_rate' => [
            'default' => null,
            'type' => WS_TYPE_FLOAT,
        ],
        'f_max_rate' => [
            'default' => null,
            'type' => WS_TYPE_FLOAT,
        ],
        'f_min_hit' => [
            'default' => null,
            'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
        ],
        'f_max_hit' => [
            'default' => null,
            'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
        ],
        'f_min_ratio' => [
            'default' => null,
            'type' => WS_TYPE_FLOAT | WS_TYPE_POSITIVE,
        ],
        'f_max_ratio' => [
            'default' => null,
            'type' => WS_TYPE_FLOAT | WS_TYPE_POSITIVE,
        ],
        'f_max_level' => [
            'default' => null,
            'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
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
        'ws_getVersion',
        null,
        'Returns the Piwigo version.',
        $ws_functions_root . 'pwg.php'
    );

    $service->addMethod(
        'pwg.getInfos',
        'ws_getInfos',
        null,
        'Returns general informations.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.getCacheSize',
        'ws_getCacheSize',
        null,
        'Returns general informations.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.activity.getList',
        'ws_getActivityList',
        [
            'page' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'offset' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'uid' => [
                'default' => null,
                'type' => WS_TYPE_ID,
            ],
            'date_min' => [
                'default' => null,
            ],
            'date_max' => [
                'default' => null,
            ],
            'id' => [
                'default' => null,
                'type' => WS_TYPE_ID,
            ],
            'object' => [
                'default' => null,
            ],
            'action' => [
                'default' => null,
            ],
        ],
        'Returns general informations.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.activity.downloadLog',
        'ws_activity_downloadLog',
        null,
        'Returns general informations.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.caddie.add',
        'ws_caddie_add',
        [
            'image_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Adds elements to the caddie. Returns the number of elements added.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.getImages',
        'ws_categories_getImages',
        array_merge([
            'cat_id' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'recursive' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_images_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => null,
                'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random',
            ],
        ], $f_params),
        'Returns elements for the corresponding categories.
<br><b>cat_id</b> can be empty if <b>recursive</b> is true.
<br><b>order</b> comma separated fields for sorting',
        $ws_functions_root . 'pwg.categories.php'
    );

    $service->addMethod(
        'pwg.categories.getList',
        'ws_categories_getList',
        [
            'cat_id' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
                'info' => 'Parent category. "0" or empty for root.',
            ],
            'recursive' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'public' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'tree_output' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'fullname' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'thumbnail_size' => [
                'default' => IMG_THUMB,
                'info' => implode(',', array_keys(ImageStdParams::get_defined_type_map())),
            ],
            'search' => [
                'default' => null,
            ],
            'limit' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
                'info' => 'Parameter not compatible with recursive=true',
            ],
        ],
        'Returns a list of categories.',
        $ws_functions_root . 'pwg.categories.php'
    );

    $service->addMethod(
        'pwg.getMissingDerivatives',
        'ws_getMissingDerivatives',
        array_merge([
            'types' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'info' => 'square, thumb, 2small, xsmall, small, medium, large, xlarge, xxlarge, 3xlarge, 4xlarge',
            ],
            'ids' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'max_urls' => [
                'default' => 200,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'prev_page' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ], $f_params),
        'Returns a list of derivatives to build.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.addComment',
        'ws_images_addComment',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
            'author' => [
                'default' => is_a_guest() ? 'guest' : $user['username'],
            ],
            'content' => [],
            'key' => [],
        ],
        'Adds a comment to an image.',
        $ws_functions_root . 'pwg.images.php',
        [
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.getInfo',
        'ws_images_getInfo',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
            'comments_page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'comments_per_page' => [
                'default' => $nb_comment_page,
                'maxValue' => 2 * $nb_comment_page,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ],
        'Returns information about an image.',
        $ws_functions_root . 'pwg.images.php'
    );

    $service->addMethod(
        'pwg.images.rate',
        'ws_images_rate',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
            'rate' => [
                'type' => WS_TYPE_FLOAT,
            ],
        ],
        'Rates an image.',
        $ws_functions_root . 'pwg.images.php'
    );

    $service->addMethod(
        'pwg.images.search',
        'ws_images_search',
        array_merge([
            'query' => [],
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_images_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => null,
                'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random',
            ],
        ], $f_params),
        'Returns elements for the corresponding query search.',
        $ws_functions_root . 'pwg.images.php'
    );

    $service->addMethod(
        'pwg.images.setPrivacyLevel',
        'ws_images_setPrivacyLevel',
        [
            'image_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'level' => [
                'maxValue' => max($available_permission_levels),
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ],
        'Sets the privacy levels for the images.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.formats.searchImage',
        'ws_images_formats_searchImage',
        [
            'filename_list' => [],
        ],
        'Search for image ids matching the provided filenames. <b>filename_list</b> must be a JSON encoded associative array of unique_id:filename.<br><br>The method returns a list of unique_id:image_id.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.formats.delete',
        'ws_images_formats_delete',
        [
            'format_id' => [
                'type' => WS_TYPE_ID,
                'default' => null,
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'pwg_token' => [],
        ],
        'Remove a format',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.setRank',
        'ws_images_setRank',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
            'rank' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL,
                'default' => null,
            ],
        ],
        'Sets the rank of a photo for a given album.
<br><br>If you provide a list for image_id:
<ul>
<li>rank becomes useless, only the order of the image_id list matters</li>
<li>you are supposed to provide the list of all image_ids belonging to the album.
</ul>',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.setCategory',
        'ws_images_setCategory',
        [
            'image_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
            'action' => [
                'default' => 'associate',
                'info' => 'associate/dissociate/move',
            ],
            'pwg_token' => [],
        ],
        'Manage associations of images with an album. <b>action</b> can be:<ul><li><i>associate</i> : add photos to this album</li><li><i>dissociate</i> : remove photos from this album</li><li><i>move</i> : dissociate photos from any other album and adds photos to this album</li></ul>',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.rates.delete',
        'ws_rates_delete',
        [
            'user_id' => [
                'type' => WS_TYPE_ID,
            ],
            'anonymous_id' => [
                'default' => null,
            ],
            'image_id' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Deletes all rates for a user.',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.session.getStatus',
        'ws_session_getStatus',
        null,
        'Gets information about the current session. Also provides a token useable with admin methods.',
        $ws_functions_root . 'pwg.php'
    );

    $service->addMethod(
        'pwg.session.login',
        'ws_session_login',
        [
            'username' => [],
            'password' => [
                'default' => null,
            ],
        ],
        'Tries to login the user.',
        $ws_functions_root . 'pwg.php',
        [
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.session.logout',
        'ws_session_logout',
        null,
        'Ends the current session.',
        $ws_functions_root . 'pwg.php'
    );

    $service->addMethod(
        'pwg.tags.getList',
        'ws_tags_getList',
        [
            'sort_by_counter' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
        ],
        'Retrieves a list of available tags.',
        $ws_functions_root . 'pwg.tags.php'
    );

    $service->addMethod(
        'pwg.tags.getImages',
        'ws_tags_getImages',
        array_merge([
            'tag_id' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'tag_url_name' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
            'tag_name' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
            'tag_mode_and' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_images_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => null,
                'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random',
            ],
        ], $f_params),
        'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.',
        $ws_functions_root . 'pwg.tags.php'
    );

    $service->addMethod(
        'pwg.images.addChunk',
        'ws_images_add_chunk',
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
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.addFile',
        'ws_images_addFile',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
            'type' => [
                'default' => 'file',
                'info' => 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.',
            ],
            'sum' => [],
        ],
        'Add or update a file for an existing photo.
<br>pwg.images.addChunk must have been called before (maybe several times).',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.add',
        'ws_images_add',
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
                'Provide it if "check_uniqueness" is true and $conf["uniqueness_mode"] is "filename".',
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
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'check_uniqueness' => [
                'default' => true,
                'type' => WS_TYPE_BOOL,
            ],
            'image_id' => [
                'default' => null,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Add an image.
<br>pwg.images.addChunk must have been called before (maybe several times).
<br>Don\'t use "thumbnail_sum" and "high_sum", these parameters are here for backward compatibility.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.addSimple',
        'ws_images_addSimple',
        [
            'category' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
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
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'tags' => [
                'default' => null,
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'image_id' => [
                'default' => null,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.upload',
        'ws_images_upload',
        [
            'name' => [
                'default' => null,
            ],
            'category' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'level' => [
                'default' => 0,
                'maxValue' => max($available_permission_levels),
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'format_of' => [
                'default' => null,
                'type' => WS_TYPE_ID,
                'info' => 'id of the extended image (name/category/level are not used if format_of is provided)',
            ],
            'update_mode' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
                'info' => 'true if the update mode is active',
            ],
            'pwg_token' => [],
        ],
        'Add an image.
<br>Use the <b>$_FILES[image]</b> field for uploading file.
<br>Set the form encoding to "form-data".',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.uploadAsync',
        'ws_images_uploadAsync',
        [
            'username' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'password' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'chunk' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'chunk_sum' => [],
            'chunks' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'original_sum' => [],
            'category' => [
                'default' => null,
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
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
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'tag_ids' => [
                'default' => null,
                'info' => 'Comma separated ids',
            ],
            'image_id' => [
                'default' => null,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Upload photo by chunks in a random order.
<br>Use the <b>$_FILES[file]</b> field for uploading file.
<br>Start with chunk 0 (zero).
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.
<br>Requires <b>admin</b> credentials: either with username/password or header authorization with api key.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.delete',
        'ws_images_delete',
        [
            'image_id' => [
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'pwg_token' => [],
        ],
        'Deletes image(s).',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.setMd5sum',
        'ws_images_setMd5sum',
        [
            'block_size' => [
                'default' => $conf['checksum_compute_blocksize'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'pwg_token' => [],
        ],
        'Set md5sum column, by blocks. Returns how many md5sums were added and how many are remaining.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.syncMetadata',
        'ws_images_syncMetadata',
        [
            'image_id' => [
                'flags' => WS_PARAM_ACCEPT_ARRAY,
                'info' => 'Comma separated ids or array of id',
            ],
            'pwg_token' => [],
        ],
        'Sync metadatas, by blocks. Returns how many images were synchronized',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.deleteOrphans',
        'ws_images_deleteOrphans',
        [
            'block_size' => [
                'default' => 1000,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'pwg_token' => [],
        ],
        'Deletes orphans, by blocks. Returns how many orphans were deleted and how many are remaining.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.calculateOrphans',
        'ws_categories_calculateOrphans',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
        ],
        'Return the number of orphan photos if an album is deleted.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.getAdminList',
        'ws_categories_getAdminList',
        [
            'cat_id' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
                'info' => 'Parent category. "0" or empty for root.',
            ],
            'search' => [
                'default' => null,
            ],
            'recursive' => [
                'default' => true,
                'type' => WS_TYPE_BOOL,
            ],
            'additional_output' => [
                'default' => null,
                'info' => 'Comma saparated list (see method description)',
            ],
        ],
        'Get albums list as displayed on admin page. <br>
      <b>additional_output</b> controls which data are returned, possible values are:<br>
      null, full_name_with_admin_links<br>',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.add',
        'ws_categories_add',
        [
            'name' => [],
            'parent' => [
                'default' => null,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'comment' => [
                'default' => null,
            ],
            'visible' => [
                'default' => true,
                'type' => WS_TYPE_BOOL,
            ],
            'status' => [
                'default' => null,
                'info' => 'public, private',
            ],
            'commentable' => [
                'default' => true,
                'type' => WS_TYPE_BOOL,
            ],
            'position' => [
                'default' => null,
                'info' => 'first, last',
            ],
            'pwg_token' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
        ],
        'Adds an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.delete',
        'ws_categories_delete',
        [
            'category_id' => [
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'photo_deletion_mode' => [
                'default' => 'delete_orphans',
            ],
            'pwg_token' => [],
        ],
        'Deletes album(s).
<br><b>photo_deletion_mode</b> can be "no_delete" (may create orphan photos), "delete_orphans"
(default mode, only deletes photos linked to no other album) or "force_delete" (delete all photos, even those linked to other albums)',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.move',
        'ws_categories_move',
        [
            'category_id' => [
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'parent' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'pwg_token' => [],
        ],
        'Move album(s).
<br>Set parent as 0 to move to gallery root. Only virtual categories can be moved.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.setRepresentative',
        'ws_categories_setRepresentative',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Sets the representative photo for an album. The photo doesn\'t have to belong to the album.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.deleteRepresentative',
        'ws_categories_deleteRepresentative',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Deletes the album thumbnail. Only possible if $conf[\'allow_random_representative\']',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.refreshRepresentative',
        'ws_categories_refreshRepresentative',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Find a new album thumbnail.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.tags.getAdminList',
        'ws_tags_getAdminList',
        null,
        '<b>Admin only.</b>',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod( // TODO: create multiple tags
        'pwg.tags.add',
        'ws_tags_add',
        [
            'name' => [],
        ],
        'Adds a new tag.',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.tags.delete',
        'ws_tags_delete',
        [
            'tag_id' => [
                'type' => WS_TYPE_ID,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
            'pwg_token' => [],
        ],
        'Delete tag(s) by ID.',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.tags.rename',
        'ws_tags_rename',
        [
            'tag_id' => [
                'type' => WS_TYPE_ID,
            ],
            'new_name' => [],
            'pwg_token' => [],
        ],
        'Rename tag',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.tags.duplicate',
        'ws_tags_duplicate',
        [
            'tag_id' => [
                'type' => WS_TYPE_ID,
            ],
            'copy_name' => [],
            'pwg_token' => [],
        ],
        'Create a copy of a tag',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.tags.merge',
        'ws_tags_merge',
        [
            'destination_tag_id' => [
                'type' => WS_TYPE_ID,
                'info' => 'Is not necessarily part of groups to merge',
            ],
            'merge_tag_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Merge tags in one other group',
        $ws_functions_root . 'pwg.tags.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.exist',
        'ws_images_exist',
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
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.checkFiles',
        'ws_images_checkFiles',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
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
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.checkUpload',
        'ws_images_checkUpload',
        null,
        'Checks if Piwigo is ready for upload.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.emptyLounge',
        'ws_images_emptyLounge',
        null,
        'Empty lounge, where images may be waiting before taking off.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.uploadCompleted',
        'ws_images_uploadCompleted',
        [
            'image_id' => [
                'default' => null,
                'flags' => WS_PARAM_ACCEPT_ARRAY,
            ],
            'pwg_token' => [],
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Notify Piwigo you have finished uploading a set of photos. It will empty the lounge, if any.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.setInfo',
        'ws_images_setInfo',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
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
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'single_value_mode' => [
                'default' => 'fill_if_empty',
            ],
            'multiple_value_mode' => [
                'default' => 'append',
            ],
            'pwg_token' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
        ],
        'Changes properties of an image.
<br><b>single_value_mode</b> can be "fill_if_empty" (only use the input value if the corresponding values is currently empty) or "replace"
(overwrite any existing value) and applies to single values properties like name/author/date_creation/comment.
<br><b>multiple_value_mode</b> can be "append" (no change on existing values, add the new values) or "replace" and applies to multiple values properties like tag_ids/categories.
<br><b>pwg_token</b> required if you want to use HTML in name/comment/author.',
        $ws_functions_root . 'pwg.images.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.setInfo',
        'ws_categories_setInfo',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
            ],
            'name' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'comment' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'status' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'public, private',
            ],
            'visible' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'commentable' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'Boolean, effective if configuration variable activate_comments is set to true',
            ],
            'apply_commentable_to_subalbums' => [
                'default' => null,
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'If true, set commentable to all sub album',
            ],
            'pwg_token' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
        ],
        'Changes properties of an album.<br><br><b>pwg_token</b> required if you want to use HTML in name/comment.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.categories.setRank',
        'ws_categories_setRank',
        [
            'category_id' => [
                'type' => WS_TYPE_ID,
                'flags' => WS_PARAM_FORCE_ARRAY,
            ],
            'rank' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL,
                'flags' => WS_PARAM_OPTIONAL,
            ],
        ],
        'Changes the rank of an album
        <br><br>If you provide a list for category_id:
        <ul>
        <li>rank becomes useless, only the order of the image_id list matters</li>
        <li>you are supposed to provide the list of all categories_ids belonging to the album.
        </ul>.',
        $ws_functions_root . 'pwg.categories.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.plugins.getList',
        'ws_plugins_getList',
        null,
        'Gets the list of plugins with id, name, version, state and description.',
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.plugins.performAction',
        'ws_plugins_performAction',
        [
            'action' => [
                'info' => 'install, activate, deactivate, uninstall, delete',
            ],
            'plugin' => [],
            'pwg_token' => [],
        ],
        null,
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.themes.performAction',
        'ws_themes_performAction',
        [
            'action' => [
                'info' => 'activate, deactivate, delete, set_default',
            ],
            'theme' => [],
            'pwg_token' => [],
        ],
        null,
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.extensions.update',
        'ws_extensions_update',
        [
            'type' => [
                'info' => 'plugins, languages, themes',
            ],
            'id' => [],
            'revision' => [],
            'pwg_token' => [],
        ],
        '<b>Webmaster only.</b>',
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.extensions.ignoreUpdate',
        'ws_extensions_ignoreupdate',
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
                'type' => WS_TYPE_BOOL,
                'info' => 'If true, all ignored extensions will be reinitilized.',
            ],
            'pwg_token' => [],
        ],
        '<b>Webmaster only.</b> Ignores an extension if it needs update.',
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.extensions.checkUpdates',
        'ws_extensions_checkupdates',
        null,
        'Checks if piwigo or extensions are up to date.',
        $ws_functions_root . 'pwg.extensions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.getList',
        'ws_groups_getList',
        [
            'group_id' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'name' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'Use "%" as wildcard.',
            ],
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_users_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => 'name',
                'info' => 'id, name, nb_users, is_default',
            ],
        ],
        'Retrieves a list of all groups. The list can be filtered.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.add',
        'ws_groups_add',
        [
            'name' => [],
            'is_default' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
        ],
        'Creates a group and returns the new group record.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.delete',
        'ws_groups_delete',
        [
            'group_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Deletes a or more groups. Users and photos are not deleted.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.setInfo',
        'ws_groups_setInfo',
        [
            'group_id' => [
                'type' => WS_TYPE_ID,
            ],
            'name' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'is_default' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'pwg_token' => [],
        ],
        'Updates a group. Leave a field blank to keep the current value.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.addUser',
        'ws_groups_addUser',
        [
            'group_id' => [
                'type' => WS_TYPE_ID,
            ],
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Adds one or more users to a group.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.deleteUser',
        'ws_groups_deleteUser',
        [
            'group_id' => [
                'type' => WS_TYPE_ID,
            ],
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Removes one or more users from a group.',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.merge',
        'ws_groups_merge',
        [
            'destination_group_id' => [
                'type' => WS_TYPE_ID,
                'info' => 'Is not necessarily part of groups to merge',
            ],
            'merge_group_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Merge groups in one other group',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.groups.duplicate',
        'ws_groups_duplicate',
        [
            'group_id' => [
                'type' => WS_TYPE_ID,
            ],
            'copy_name' => [],
            'pwg_token' => [],
        ],
        'Create a copy of a group',
        $ws_functions_root . 'pwg.groups.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.getList',
        'ws_users_getList',
        [
            'user_id' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'username' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'Use "%" as wildcard.',
            ],
            'status' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'info' => 'guest,generic,normal,admin,webmaster',
            ],
            'min_level' => [
                'default' => 0,
                'maxValue' => max($available_permission_levels),
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'group_id' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_users_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => 'id',
                'info' => 'id, username, level, email',
            ],
            'exclude' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
                'info' => 'Expects a user_id as value.',
            ],
            'display' => [
                'default' => 'basics',
                'info' => 'Comma saparated list (see method description)',
            ],
            'filter' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'Filter by username, email, group',
            ],
            'min_register' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'See method description',
            ],
            'max_register' => [
                'flags' => WS_PARAM_OPTIONAL,
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
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.add',
        'ws_users_add',
        [
            'username' => [],
            'auto_password' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
                'info' => 'if true ignores password and confirm password',
            ],
            'password' => [
                'default' => null,
            ],
            'password_confirm' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'email' => [
                'default' => null,
            ],
            'send_password_by_mail' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'pwg_token' => [],
        ],
        'Registers a new user.',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.delete',
        'ws_users_delete',
        [
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Deletes on or more users. Photos owned by this user are not deleted.',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.getAuthKey',
        'ws_users_getAuthKey',
        [
            'user_id' => [
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.setInfo',
        'ws_users_setInfo',
        [
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'username' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'password' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'email' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'status' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'guest,generic,normal,admin,webmaster',
            ],
            'level' => [
                'flags' => WS_PARAM_OPTIONAL,
                'maxValue' => max($available_permission_levels),
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'language' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'theme' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'group_id' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_INT,
            ],
            // bellow are parameters removed in a future version
            'nb_image_page' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL,
            ],
            'recent_period' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'expand' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'show_nb_comments' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'show_nb_hits' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'enabled_high' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'pwg_token' => [],
        ],
        'Updates a user. Leave a field blank to keep the current value.
<br>"username", "password" and "email" are ignored if "user_id" is an array.
<br>set "group_id" to -1 if you want to dissociate users from all groups',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.setMyInfo',
        'ws_users_setMyInfo',
        [
            'email' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'nb_image_page' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL,
            ],
            'theme' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'language' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'recent_period' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'expand' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'show_nb_comments' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'show_nb_hits' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
            ],
            'password' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'new_password' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'conf_new_password' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'pwg_token' => [],
        ],
        '',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => false,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.permissions.getList',
        'ws_permissions_getList',
        [
            'cat_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'group_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
        ],
        'Returns permissions: user ids and group ids having access to each album ; this list can be filtered.
<br>Provide only one parameter!',
        $ws_functions_root . 'pwg.permissions.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.permissions.add',
        'ws_permissions_add',
        [
            'cat_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'group_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'recursive' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
            'pwg_token' => [],
        ],
        'Adds permissions to an album.',
        $ws_functions_root . 'pwg.permissions.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.permissions.remove',
        'ws_permissions_remove',
        [
            'cat_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'group_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'user_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY | WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Removes permissions from an album.',
        $ws_functions_root . 'pwg.permissions.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.preferences.set',
        'ws_users_preferences_set',
        [
            'param' => [],
            'value' => [
                'flags' => WS_PARAM_OPTIONAL,
            ],
            'is_json' => [
                'default' => false,
                'type' => WS_TYPE_BOOL,
            ],
        ],
        'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.',
        $ws_functions_root . 'pwg.users.php'
    );

    $service->addMethod(
        'pwg.users.favorites.add',
        'ws_users_favorites_add',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Adds the indicated image to the current user\'s favorite images.',
        $ws_functions_root . 'pwg.users.php'
    );

    $service->addMethod(
        'pwg.users.favorites.remove',
        'ws_users_favorites_remove',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
        ],
        'Removes the indicated image from the current user\'s favorite images.',
        $ws_functions_root . 'pwg.users.php'
    );

    $service->addMethod(
        'pwg.users.favorites.getList',
        'ws_users_favorites_getList',
        [
            'per_page' => [
                'default' => 100,
                'maxValue' => $conf['ws_max_images_per_page'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'order' => [
                'default' => null,
                'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random',
            ],
        ],
        'Returns the favorite images of the current user.',
        $ws_functions_root . 'pwg.users.php'
    );

    $service->addMethod(
        'pwg.history.log',
        'ws_history_log',
        [
            'image_id' => [
                'type' => WS_TYPE_ID,
            ],
            'cat_id' => [
                'type' => WS_TYPE_ID,
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
                'type' => WS_TYPE_BOOL,
            ],
        ],
        'Log visit in history',
        $ws_functions_root . 'pwg.php'
    );

    $service->addMethod(
        'pwg.history.search',
        'ws_history_search',
        [
            'start' => [
                'default' => null,
            ],
            'end' => [
                'default' => null,
            ],
            'types' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
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
                'type' => WS_TYPE_ID,
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
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ],
        'Gives an history of who has visited the galery and the actions done in it. Receives parameter.
      <br> <strong>Types </strong> can be : \'none\', \'picture\', \'high\', \'other\'
      <br> <strong>Date format</strong> is yyyy-mm-dd
      <br> <strong>display_thumbnail</strong> can be : \'no_display_thumbnail\', \'display_thumbnail_classic\', \'display_thumbnail_hoverbox\'',
        $ws_functions_root . 'pwg.php',
        [
            'admin_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.images.filteredSearch.create',
        'ws_images_filteredSearch_create',
        [
            'search_id' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'prior search_id (or search_key), if any',
            ],
            'allwords' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'query to search by words',
            ],
            'allwords_mode' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'AND (by default) | OR',
            ],
            'allwords_fields' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'info' => 'values among [name, comment, tags, file, author, cat-title, cat-desc]',
            ],
            'tags' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'tags_mode' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'AND (by default) | OR',
            ],
            'categories' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'categories_withsubs' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
                'info' => 'false, by default',
            ],
            'authors' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
            ],
            'added_by' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_ID,
            ],
            'filetypes' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
            ],
            'date_posted_preset' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'files posted within 24 hours, 7 days, 30 days, 3 months, 6 months or custom. Value among 24h|7d|30d|3m|6m|custom.',
            ],
            'date_posted_custom' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'info' => 'Must be provided if date_posted_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.',
            ],
            'date_created_preset' => [
                'flags' => WS_PARAM_OPTIONAL,
                'info' => 'files created within 7 days, 30 days, 3 months, 6 months, 12 months or custom. Value among 7d|30d|3m|6m|12m|custom.',
            ],
            'date_created_custom' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
                'info' => 'Must be provided if date_created_preset is custom. List of yYYYY or mYYYY-MM or dYYYY-MM-DD.',
            ],
            'ratios' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
            ],
            'ratings' => [
                'flags' => WS_PARAM_OPTIONAL | WS_PARAM_FORCE_ARRAY,
            ],
            'filesize_min' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'filesize_max' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'height_min' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'height_max' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'width_min' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'width_max' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ],
        '',
        $ws_functions_root . 'pwg.images.php'
    );

    $service->addMethod(
        'pwg.users.generatePasswordLink',
        'ws_users_generate_password_link',
        [
            'user_id' => [
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
            'send_by_mail' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_BOOL,
                'default' => false,
            ],
        ],
        'Return the reset password link <br />
       (Only webmaster can perform this action for another webmaster)',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.setMainUser',
        'ws_set_main_user',
        [
            'user_id' => [
                'type' => WS_TYPE_ID,
            ],
            'pwg_token' => [],
        ],
        'Update the main user (owner) <br />
        - To be the main user, the user must have the status "webmaster".<br />
        - Only a webmaster can perform this action',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.api_key.create',
        'ws_create_api_key',
        [
            'key_name' => [],
            'duration' => [
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
                'info' => 'Number of days',
            ],
            'pwg_token' => [],
        ],
        'Create a new api key for the user in the current session',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => false,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.api_key.revoke',
        'ws_revoke_api_key',
        [
            'pkid' => [],
            'pwg_token' => [],
        ],
        'Revoke a api key for the user in the current session',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => false,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.api_key.edit',
        'ws_edit_api_key',
        [
            'key_name' => [],
            'pkid' => [],
            'pwg_token' => [],
        ],
        'Edit a api key for the user in the current session',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => false,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.users.api_key.get',
        'ws_get_api_key',
        [
            'pwg_token' => [],
        ],
        'Get all api key for the user in the current session',
        $ws_functions_root . 'pwg.users.php',
        [
            'admin_only' => false,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.userComments.getList',
        'ws_userComments_getList',
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
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'image_id' => [
                'flags' => WS_PARAM_OPTIONAL,
                'type' => WS_TYPE_ID,
            ],
            'f_min_date' => [
                'default' => null,
            ],
            'f_max_date' => [
                'default' => null,
            ],
            'page' => [
                'default' => 0,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'per_page' => [
                'default' => $conf['comments_page_nb_comments'],
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
        ],
        'Get comments',
        $ws_functions_root . 'pwg.comments.php',
        [
            'admin_only' => true,
            'post_only' => false,
        ]
    );

    $service->addMethod(
        'pwg.userComments.delete',
        'ws_userComments_delete',
        [
            'comment_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'pwg_token' => [],
        ],
        'Delete comments',
        $ws_functions_root . 'pwg.comments.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );

    $service->addMethod(
        'pwg.userComments.validate',
        'ws_userComments_validate',
        [
            'comment_id' => [
                'flags' => WS_PARAM_FORCE_ARRAY,
                'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
            ],
            'pwg_token' => [],
        ],
        'Validate comments',
        $ws_functions_root . 'pwg.comments.php',
        [
            'admin_only' => true,
            'post_only' => true,
        ]
    );
}
