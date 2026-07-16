<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Ported from admin/batch_manager_unit.php (the "unit" mode tab of the
 * "batch_manager" page slug, dispatched by BatchManagerSubController) --
 * per-photo inline edit grid (name/author/level/description/date/tags for
 * each photo individually, in the current filtered selection).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original file's own (redundant) check_status() call is dropped here --
 * same precedent as every prior P23 batch 6 sub-batch. The one real
 * mutation path (isset($_POST['submit'])) already has its own
 * check_pwg_token() call -- no CSRF gap here.
 *
 * Preserves 2 pre-existing quirks unchanged (a mechanical port doesn't fold
 * in unrelated fixes, same discipline as every prior sub-batch):
 *  - $base_url is built from PHPWG_ROOT_PATH (a filesystem path constant),
 *    not get_root_url() like every sibling renderer -- almost certainly a
 *    pre-existing bug (U_ELEMENTS_PAGE/F_ACTION would render a filesystem
 *    path, not a URL), but out of scope for this port.
 *  - the "$storage_category_id" block below the images query reads
 *    whatever $row the earlier "unit mode form submission" while-loop left
 *    behind (or undefined, if that block didn't run) rather than the
 *    current image's own row -- so STORAGE_CATEGORY highlighting never
 *    triggers for the correct album in practice. Documented in the
 *    original file as a known, not-fixed-here bug; carried forward
 *    verbatim.
 */
final class BatchManagerUnitPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $cache
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var array<string, mixed> $pwg_loaded_plugins
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $cache, $conf, $page, $pwg_loaded_plugins, $template, $user;

        trigger_notify('loc_begin_element_set_unit');

        // $page is bootstrap-initialized by include/common.inc.php with
        // 'infos'/'errors'/'warnings'/'messages'/'body_classes'/'body_data'
        // pre-populated as empty arrays; this file only ever appends to 'infos'
        // (matches the same established pattern in BatchManagerGlobalPageRenderer).
        // Declared unconditionally here, before the first (conditional) use below,
        // so the narrowing holds for every later read/write in this file.
        assert(is_array($page['infos']));

        // +-------------------------------------------------------------------+
        // |                        unit mode form submission                      |
        // +-------------------------------------------------------------------+

        if (isset($_POST['submit'])) {
            check_pwg_token();
            (new \Piwigo\Validation\InputValidator())->validate('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
            $element_ids_param = $_POST['element_ids'] ?? null;
            $collection = explode(',', is_string($element_ids_param) ? $element_ids_param : '');

            $datas = [];

            $query = '
SELECT id, date_creation
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $collection) . ')
;';
            $result = pwg_query($query);

            $tagConn = DbConnection::build();
            $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));

            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic string|null element type
                // pwg_db_fetch_assoc() carries for every column.
                if ($row['id'] === null) {
                    continue;
                }
                $image_id = (int) $row['id'];

                $data = [];

                $data['id'] = $row['id'];
                $data['name'] = $_POST['name-' . $row['id']];
                $data['author'] = $_POST['author-' . $row['id']];
                $data['level'] = $_POST['level-' . $row['id']];

                if ((bool) $conf['allow_html_descriptions']) {
                    $data['comment'] = @$_POST['description-' . $row['id']];
                } else {
                    $description_post = $_POST['description-' . $row['id']] ?? null;
                    $data['comment'] = strip_tags(is_string($description_post) ? $description_post : '');
                }

                if (($_POST['date_creation-' . $row['id']] ?? '') !== '') {
                    $data['date_creation'] = $_POST['date_creation-' . $row['id']];
                } else {
                    $data['date_creation'] = null;
                }

                $datas[] = $data;

                // tags management
                $tag_ids = [];
                $raw_tags_post = $_POST['tags-' . $row['id']] ?? null;
                if ($raw_tags_post !== null && $raw_tags_post !== '' && $raw_tags_post !== '0' && $raw_tags_post !== []) {
                    if (is_array($raw_tags_post)) {
                        $tag_ids = $tagService->getTagIds(array_filter($raw_tags_post, is_string(...)));
                    } elseif (is_string($raw_tags_post)) {
                        $tag_ids = $tagService->getTagIds($raw_tags_post);
                    }
                }
                $tagService->setTags($tag_ids, $image_id);
            }

            mass_updates(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => ['name', 'author', 'level', 'comment', 'date_creation'],
                ],
                $datas
            );

            $page['infos'][] = l10n('Photo informations updated');
            UserCacheInvalidator::invalidate();
        }

        // collection
        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            (new \Piwigo\Validation\InputValidator())->validate('nb_photos_deleted', $_POST, false, '/^\d+$/');

            // let's fake a collection (we don't know the image_ids so we use "null", we only
            // care about the number of items here)
            $nb_photos_deleted = is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0;
            $collection = array_fill(0, $nb_photos_deleted, null);
        } elseif (isset($_POST['setSelected'])) {
            // Here we don't use check_input_parameter because preg_match has a limit in
            // the repetitive pattern. Found a limit to 3276 but may depend on memory.
            //
            // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
            //
            // Instead, let's break the input parameter into pieces and check pieces one by one.
            $whole_set_param = $_POST['whole_set'] ?? null;
            $collection = explode(',', is_string($whole_set_param) ? $whole_set_param : '');

            foreach ($collection as $id) {
                if (! (bool) preg_match('/^\d+$/', $id)) {
                    fatal_error('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
            }
        } elseif (isset($_POST['selection']) && is_array($_POST['selection'])) {
            $collection = $_POST['selection'];
        }

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames(
            [
                'batch_manager_unit' => 'batch_manager_unit.tpl',
            ]
        );

        $base_url = PHPWG_ROOT_PATH . 'admin.php';

        $template->assign(
            [

                'U_ELEMENTS_PAGE' => $base_url . get_query_string_diff(['display', 'start']),
                'level_options' => \Piwigo\Permission\PermissionService::getPrivacyLevelOptions(),
                'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
                'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
            ]
        );

        // $page['cat_elements_id']/$page['start'] are always set (a list of
        // scalar image ids / 0 or a validated numeric $_REQUEST value) by
        // BatchManagerSubController before dispatching to this renderer;
        // PHPStan cannot see across that boundary, so we narrow both once
        // here for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = is_array($page['cat_elements_id']) ? array_filter($page['cat_elements_id'], is_scalar(...)) : [];
        $page_start = is_numeric($page['start']) ? (int) $page['start'] : 0;

        new FilterPanelRenderer()
            ->render($template, $base_url, $collection, $cat_elements_id, $page_start);
        // +-------------------------------------------------------------------+
        // |                        global mode thumbnails                         |
        // +-------------------------------------------------------------------+

        $template->assign('ACTIVE_PLUGINS', array_keys($pwg_loaded_plugins));

        // how many items to display on this page
        if (isset($_GET['display']) && $_GET['display'] !== '' && $_GET['display'] !== '0') {
            // conf_update_param('batch_manager_images_per_page_unit' , intval($_GET['display']));
            // $page['nb_images'] = $conf['batch_manager_images_per_page_unit'];
            $page['nb_images'] = is_numeric($_GET['display']) ? intval($_GET['display']) : 0;
        } elseif (in_array($conf['batch_manager_images_per_page_unit'], [5, 10, 50], true)) {
            $page['nb_images'] = $conf['batch_manager_images_per_page_unit'];
        } else {
            $page['nb_images'] = 5;
        }
        $template->assign('per_page', $page['nb_images']);

        if (count($cat_elements_id) > 0) {
            // $page['nb_images'] is always int here, same reasoning as
            // BatchManagerGlobalPageRenderer's own nb_images narrowing.
            $page_nb_images = $page['nb_images'];

            $nav_bar = (new \Piwigo\Core\PaginationService())->createNavigationBar($base_url . get_query_string_diff(['start']), count($cat_elements_id), $page_start, $page_nb_images);
            $template->assign([
                'navbar' => $nav_bar,
            ]);

            $element_ids = [];

            // Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is
            // always written as an array by BatchManagerSubController (which runs
            // before dispatching to this renderer); this guards against
            // corrupted/foreign session state and lets PHPStan track a real array
            // shape for the reads below (this file never writes to
            // $_SESSION['bulk_manager_filter']).
            /** @var array<string, mixed> $bulk_manager_filter */
            $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

            $is_category = false;
            $filter_category_id = 0;
            if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])
                and ! isset($bulk_manager_filter['category_recursive'])) {
                $is_category = true;
                $filter_category_id = (int) $bulk_manager_filter['category'];
            }

            if (isset($bulk_manager_filter['prefilter'])
                and $bulk_manager_filter['prefilter'] === 'duplicates') {
                $conf['order_by'] = ' ORDER BY file, id';
            }

            $query = '
SELECT *
  FROM ' . Tables::images();

            if ($is_category) {
                $category_info = get_cat_info($filter_category_id);

                $conf['order_by'] = $conf['order_by_inside_category'];
                if (is_string($category_info['image_order'] ?? null) && $category_info['image_order'] !== '') {
                    $conf['order_by'] = ' ORDER BY ' . $category_info['image_order'];
                }

                $query .= '
    JOIN ' . Tables::imageCategory() . ' ON id = image_id';
            }

            $query .= '
  WHERE id IN (' . implode(',', $cat_elements_id) . ')';

            if ($is_category) {
                $query .= '
    AND category_id = ' . $filter_category_id;
            }

            $query .= '
  ' . (is_string($conf['order_by']) ? $conf['order_by'] : '') . '
  LIMIT ' . $page_nb_images . ' OFFSET ' . $page_start . '
;';
            // $result = pwg_query($query);
            $images = query2array($query);
            $added_by_ids = array_unique(array_column($images, 'added_by'));
            // Defaults to empty so the read inside the foreach loop below is always
            // a real array, whether or not $added_by_ids was non-empty (the
            // foreach loop only ever runs when $images -- and therefore
            // $added_by_ids -- is non-empty, but this default avoids relying on
            // that cross-block invariant).
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                // $conf['user_fields'] maps generic field names to table-specific
                // DB column names (see include/config_default.inc.php); matches
                // the established narrowing pattern used across
                // include/functions_user.inc.php.
                /** @var array<string, string> $user_fields */
                $user_fields = $conf['user_fields'];
                $query = '
SELECT
    ' . $user_fields['username'] . ' AS username,
    ' . $user_fields['id'] . ' AS id
  FROM ' . Tables::users() . '
  WHERE ' . $user_fields['id'] . ' IN ( ' . implode(',', $added_by_ids) . ' )
;';
                $added_by_username_of = query2array($query, 'id', 'username');
            }

            // NOTE (pre-existing bug, not fixed here): $row is not defined by a
            // per-image loop at this point -- it is whatever the earlier
            // "unit mode form submission" while-loop (above) left behind (or
            // undefined, if that block didn't run), not the current image's
            // row. $storage_category_id is therefore effectively always null in
            // practice, and the STORAGE_CATEGORY highlighting below never
            // triggers for the correct album. The isset()/is_array() guards here
            // only preserve the current (buggy) runtime behavior for PHPStan.
            $storage_category_id = null;
            if (isset($row) && is_array($row)
                && is_string($row['storage_category_id'] ?? null)
                && $row['storage_category_id'] !== ''
                && $row['storage_category_id'] !== '0') {
                $storage_category_id = $row['storage_category_id'];
            }

            $tagConn = DbConnection::build();
            $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));
            $imageService = new ImageService(new ImageRepository($tagConn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));

            foreach ($images as $row) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic string|null element type
                // query2array() carries for every column.
                if ($row['id'] === null) {
                    continue;
                }

                $element_ids[] = $row['id'];

                $src_image = new SrcImage($row);

                $image_file = $row['file'];

                $query = '
SELECT
    id,
    name
  FROM ' . Tables::imageTag() . ' AS it
    JOIN ' . Tables::tags() . ' AS t ON t.id = it.tag_id
  WHERE image_id = ' . $row['id'] . '
;';

                $tag_selection = $tagService->getTagList($query);

                $row_file = is_string($row['file']) ? $row['file'] : '';
                $legend = render_element_name($row);
                if ($legend !== \Piwigo\Core\StringHelper::getNameFromFile($row_file)) {
                    $legend .= ' (' . $row['file'] . ')';
                }
                $extTab = explode('.', (string) $row['path']);

                // represent

                // categories

                $query = '
    SELECT category_id, uppercats, dir
      FROM ' . Tables::imageCategory() . ' AS ic
        INNER JOIN ' . Tables::categories() . ' AS c
          ON c.id = ic.category_id
      WHERE image_id = ' . $row['id'] . '
    ;';

                $sub_result = pwg_query($query);
                $related_categories = [];
                $related_category_ids = [];
                $media = [
                    'image' => $imageService->getImageInfos($row['id'], true),
                ];
                // die_on_missing=true means getImageInfos() only returns null via
                // a fatal_error() path that never returns.
                assert($media['image'] !== null);

                while ((bool) ($item = pwg_db_fetch_assoc($sub_result))) {
                    // Tables::imageCategory()/Tables::categories().category_id/uppercats are
                    // NOT NULL; this guard only defends against the generic
                    // string|null element type pwg_db_fetch_assoc() carries for
                    // every column.
                    if ($item['category_id'] === null || $item['uppercats'] === null) {
                        continue;
                    }

                    $name =
                      get_cat_display_name_cache(
                          $item['uppercats'],
                          get_root_url() . 'admin.php?page=album-'
                      );

                    if ($item['category_id'] === $storage_category_id) {
                        $template->assign('STORAGE_CATEGORY', $name);
                    }

                    $related_categories[$item['category_id']] = [
                        'name' => $name,
                        'unlinkable' => $item['category_id'] !== $storage_category_id,
                    ];
                    $related_category_ids[] = $item['category_id'];
                }

                // jump to link
                $image_file = $row['file'];

                $query = '
    SELECT category_id
    FROM ' . Tables::imageCategory() . '
    WHERE image_id = ' . $row['id'] . '
    ;';
                // $user['id']/$user['status'] are always numeric/string
                // respectively: include/user.inc.php (part of the
                // include/common.inc.php bootstrap that always runs before this
                // file) populates $user via build_user(), whose 'id' is always the
                // int passed to it and whose 'status' always comes from the
                // Tables::userInfos().status column (a NOT NULL string column) --
                // matches the same established pattern in
                // BatchManagerGlobalPageRenderer.
                assert(is_numeric($user['id']));
                assert(is_string($user['status']));
                $authorizeds = array_diff(
                    array_filter(query2array($query, null, 'category_id'), is_string(...)),
                    explode(
                        ',',
                        (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getForbiddenCategories((int) $user['id'], $user['status'])
                    )
                );

                // $cache['cat_names'] is populated as array<int|string, array<string,
                // mixed>> by get_cat_display_name_cache() (already called above,
                // for every $item in the while loop, before this point) -- matches
                // the established narrowing pattern in Piwigo\Admin\
                // PictureModifyPageRenderer.
                $cat_names = is_array($cache['cat_names']) ? $cache['cat_names'] : [];

                if (isset($row['cat_id'])
                and in_array($row['cat_id'], $authorizeds, true)) {
                    $url_img = make_picture_url(
                        [
                            'image_id' => $row['id'],
                            'image_file' => $image_file,
                            'category' => $cat_names[$row['cat_id']],
                        ]
                    );
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = make_picture_url(
                            [
                                'image_id' => $row['id'], // utile ?
                                'image_file' => $image_file,
                                'category' => $cat_names[$category],
                            ]
                        );
                        break;
                    }
                }
                $admin_photo_base_url = get_root_url() . 'admin.php?page=photo-' . $row['id'];
                $admin_url_start = $admin_photo_base_url . '-properties';
                $admin_url_start .= isset($row['cat_id']) ? '&amp;cat_id=' . $row['cat_id'] : '';
                $selected_level = $row['level'] ?? $row['level'];
                $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                $row_date_available = is_string($row['date_available']) ? $row['date_available'] : '';

                $template->append(
                    'elements',
                    array_merge(
                        $row,
                        [
                            'ID' => $row['id'],
                            'TN_SRC' => DerivativeImage::url(IMG_MEDIUM, $src_image),
                            'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),
                            'LEGEND' => $legend,
                            'U_EDIT' => get_root_url() . 'admin.php?page=photo-' . $row['id'],
                            'NAME' => htmlspecialchars($row['name'] ?? ''),
                            'AUTHOR' => htmlspecialchars($row['author'] ?? ''),
                            'LEVEL' => ($row['level'] ?? '') !== '' && $row['level'] !== '0' ? $row['level'] : '0',
                            'DESCRIPTION' => htmlspecialchars($row['comment'] ?? ''),
                            'DATE_CREATION' => $row['date_creation'],
                            'TAGS' => $tag_selection,
                            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
                            'TITLE' => render_element_name($row),
                            'DIMENSIONS' => @$row['width'] . 'x' . @$row['height'] . ' px',
                            'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0, // 0:horizontal, 1:vertical
                            'FILESIZE' => l10n('%.2f MB', $row_filesize / 1024),
                            'REGISTRATION_DATE' => \Piwigo\Core\DateHelper::formatDate($row_date_available),
                            'EXT' => l10n('%s file type', end($extTab)),
                            'POST_DATE' => l10n('Added on %s', \Piwigo\Core\DateHelper::formatDate($row_date_available, ['day', 'month', 'year'])),
                            'AGE' => l10n(ucfirst(\Piwigo\Core\DateHelper::timeSince($row_date_available, 'year'))),
                            'ADDED_BY' => l10n('Added by %s', is_string($row['added_by']) ? ($added_by_username_of[$row['added_by']] ?? l10n('N/A')) : l10n('N/A')),
                            'STATS' => l10n('Visited %d times', $row['hit']),
                            'FILE' => l10n('%s', $row['file']),
                            'related_categories' => $related_categories,
                            'related_category_ids' => json_encode($related_category_ids),
                            'U_JUMPTO' => (isset($url_img) and $user['level'] >= $media['image']['level']) ? $url_img : null,
                            'tag_selection' => $tag_selection,
                            'U_DOWNLOAD' => 'action.php?id=' . $row['id'] . '&amp;part=e&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken() . '&amp;download',
                            'U_HISTORY' => get_root_url() . 'admin.php?page=history&amp;filter_image_id=' . $row['id'],
                            'U_ACTIVITY' => get_root_url() . 'admin.php?page=user_activity&photo=' . $row['id'],
                            'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken(),
                            'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1',
                            'PATH' => $row['path'],
                            'level_options_selected' => [$selected_level],

                        ]
                    )
                );
            }

            $template->assign([
                'ELEMENT_IDS' => implode(',', $element_ids),
            ]);
        }

        $template->assign([
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys(['tags', 'categories']),
        ]);

        trigger_notify('loc_end_element_set_unit');

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
    }
}
