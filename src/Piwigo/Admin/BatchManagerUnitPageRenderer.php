<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
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
    private static function tagService(Connection $conn): TagService
    {
        return new TagService(
            new TagRepository($conn),
            self::permissionService($conn),
            new ActivityService(new ActivityRepository($conn))
        );
    }

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same PermissionService
     * recipe was repeated verbatim at 2 sites in this file.
     */
    private static function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn));
    }

    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(new CategoryRepository($conn), self::permissionService($conn));
    }

    /**
     * @param array<mixed> $catElementsId
     */
    public function render(array $catElementsId, int $pageStart): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = new HtmlService();
        $conn = DbConnection::build();

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_element_set_unit');

        // +-------------------------------------------------------------------+
        // |                        unit mode form submission                      |
        // +-------------------------------------------------------------------+

        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer);
            new \Piwigo\Validation\InputValidator()
                ->validate('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
            $element_ids_param = $_POST['element_ids'] ?? null;
            $collection = explode(',', is_string($element_ids_param) ? $element_ids_param : '');

            $datas = [];

            $query = '
SELECT id, date_creation
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $collection) . ')
;';
            $tagService = self::tagService($conn);

            foreach ($conn->fetchAllAssociative($query) as $row) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic mixed element type a
                // fetched row carries for every column.
                if ($row['id'] === null || ! is_scalar($row['id'])) {
                    continue;
                }
                $row_id_str = (string) $row['id'];
                $image_id = (int) $row['id'];

                $data = [];

                $data['id'] = $row['id'];
                $data['name'] = $_POST['name-' . $row_id_str];
                $data['author'] = $_POST['author-' . $row_id_str];
                $data['level'] = $_POST['level-' . $row_id_str];

                if (\Piwigo\Config\Config::allowHtmlDescriptions()) {
                    $data['comment'] = @$_POST['description-' . $row_id_str];
                } else {
                    $description_post = $_POST['description-' . $row_id_str] ?? null;
                    $data['comment'] = strip_tags(is_string($description_post) ? $description_post : '');
                }

                if (($_POST['date_creation-' . $row_id_str] ?? '') !== '') {
                    $data['date_creation'] = $_POST['date_creation-' . $row_id_str];
                } else {
                    $data['date_creation'] = null;
                }

                $datas[] = $data;

                // tags management
                $tag_ids = [];
                $raw_tags_post = $_POST['tags-' . $row_id_str] ?? null;
                if ($raw_tags_post !== null && $raw_tags_post !== '' && $raw_tags_post !== '0' && $raw_tags_post !== []) {
                    if (is_array($raw_tags_post)) {
                        $tag_ids = $tagService->getTagIds(array_filter($raw_tags_post, is_string(...)));
                    } elseif (is_string($raw_tags_post)) {
                        $tag_ids = $tagService->getTagIds($raw_tags_post);
                    }
                }
                $tagService->setTags($tag_ids, $image_id);
            }

            new BatchWriter($conn)
                ->massUpdate(
                    Tables::images(),
                    [
                        'primary' => ['id'],
                        'update' => ['name', 'author', 'level', 'comment', 'date_creation'],
                    ],
                    $datas
                );

            \Piwigo\Core\PageState::current()->addInfo(l10n('Photo informations updated'));
            UserCacheInvalidator::invalidate();
        }

        // collection
        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            new \Piwigo\Validation\InputValidator()
                ->validate('nb_photos_deleted', $_POST, false, '/^\d+$/');

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
                    $htmlRenderer->fatalError('[Hacking attempt] the input parameter "whole_set" is not valid');
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
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        // $catElementsId is a list of scalar image ids; narrowed once here
        // for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = array_filter($catElementsId, is_scalar(...));
        $page_start = $pageStart;

        new FilterPanelRenderer()
            ->render($template, $base_url, $collection, $cat_elements_id, $page_start);
        // +-------------------------------------------------------------------+
        // |                        global mode thumbnails                         |
        // +-------------------------------------------------------------------+

        $template->assign('ACTIVE_PLUGINS', array_keys(LoadedPlugins::get()));

        // how many items to display on this page
        if (isset($_GET['display']) && $_GET['display'] !== '' && $_GET['display'] !== '0') {
            // \Piwigo\Config\ConfigDb::confUpdateParam('batch_manager_images_per_page_unit' , intval($_GET['display']));
            // $nb_images = \Piwigo\Config\Config::batchManagerImagesPerPageUnit();
            $nb_images = is_numeric($_GET['display']) ? intval($_GET['display']) : 0;
        } elseif (in_array(\Piwigo\Config\Config::batchManagerImagesPerPageUnit(), [5, 10, 50], true)) {
            $nb_images = \Piwigo\Config\Config::batchManagerImagesPerPageUnit();
        } else {
            $nb_images = 5;
        }
        $template->assign('per_page', $nb_images);

        if (count($cat_elements_id) > 0) {
            $page_nb_images = $nb_images;

            $nav_bar = new \Piwigo\Core\PaginationService()
                ->createNavigationBar($base_url . get_query_string_diff(['start']), count($cat_elements_id), $page_start, $page_nb_images);
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
                $order_by = ' ORDER BY file, id';
            } else {
                // Config::orderBy() (the typed SCHEMA accessor) models a
                // structured {field,dir}[] shape that no real code writes --
                // 'order_by' is actually stored as a raw "ORDER BY ..." SQL
                // fragment (see ConfigDb::loadConfFromDb()'s own docblock),
                // so read it via the untyped bag like ConfigService::
                // confGetParam() does for keys without a compatible accessor.
                $order_by_conf = \Piwigo\Config\Config::all()['order_by'] ?? null;
                $order_by = is_string($order_by_conf) ? $order_by_conf : '';
            }

            $query = '
SELECT *
  FROM ' . Tables::images();

            if ($is_category) {
                $category_info = self::categoryService($conn)->getCategoryInfo($filter_category_id);

                $order_by_inside_category_conf = \Piwigo\Config\Config::all()['order_by_inside_category'] ?? null;
                $order_by = is_string($order_by_inside_category_conf) ? $order_by_inside_category_conf : '';
                if (is_string($category_info['image_order'] ?? null) && $category_info['image_order'] !== '') {
                    $order_by = ' ORDER BY ' . $category_info['image_order'];
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
  ' . $order_by . '
  LIMIT ' . $page_nb_images . ' OFFSET ' . $page_start . '
;';
            $images = $conn->fetchAllAssociative($query);
            $added_by_ids = array_unique(array_map(strval(...), array_filter(
                array_column($images, 'added_by'),
                static fn (mixed $v): bool => is_int($v) || is_string($v)
            )));
            // Defaults to empty so the read inside the foreach loop below is always
            // a real array, whether or not $added_by_ids was non-empty (the
            // foreach loop only ever runs when $images -- and therefore
            // $added_by_ids -- is non-empty, but this default avoids relying on
            // that cross-block invariant).
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                $user_fields = \Piwigo\Config\Config::userFields();
                $query = '
SELECT
    ' . $user_fields['username'] . ' AS username,
    ' . $user_fields['id'] . ' AS id
  FROM ' . Tables::users() . '
  WHERE ' . $user_fields['id'] . ' IN ( ' . implode(',', $added_by_ids) . ' )
;';
                $added_by_username_of = array_column($conn->fetchAllAssociative($query), 'username', 'id');
            }

            // NOTE (pre-existing bug, not fixed here): $row is not defined by a
            // per-image loop at this point -- it is whatever the earlier
            // "unit mode form submission" foreach (above) left behind (or
            // undefined, if that block didn't run), not the current image's
            // row. $storage_category_id is therefore effectively always null in
            // practice, and the STORAGE_CATEGORY highlighting below never
            // triggers for the correct album. The isset() guard here only
            // preserves the current (buggy) runtime behavior for PHPStan
            // (is_array($row) is now provably redundant: the only assignment
            // site is a foreach over fetchAllAssociative() rows, always arrays).
            $storage_category_id = null;
            if (isset($row)
                && is_string($row['storage_category_id'] ?? null)
                && $row['storage_category_id'] !== ''
                && $row['storage_category_id'] !== '0') {
                $storage_category_id = $row['storage_category_id'];
            }

            $tagService = self::tagService($conn);
            $imageService = new ImageService(new ImageRepository($conn), new ActivityService(new ActivityRepository($conn)));

            foreach ($images as $row) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic mixed element type a
                // fetched row carries for every column.
                if ($row['id'] === null || (! is_int($row['id']) && ! is_string($row['id']))) {
                    continue;
                }
                $row_id = $row['id'];
                $row_id_str = (string) $row_id;

                $element_ids[] = $row_id;

                $src_image = new SrcImage($row);

                $image_file = $row['file'];

                $query = '
SELECT
    id,
    name
  FROM ' . Tables::imageTag() . ' AS it
    JOIN ' . Tables::tags() . ' AS t ON t.id = it.tag_id
  WHERE image_id = ' . $row_id_str . '
;';

                $tag_selection = $tagService->getTagList($query, $htmlRenderer);

                $row_file = is_string($row['file']) ? $row['file'] : '';
                $legend = $htmlRenderer->renderElementName($row);
                if ($legend !== \Piwigo\Core\StringHelper::getNameFromFile($row_file)) {
                    $legend .= ' (' . $row_file . ')';
                }
                $row_path = is_scalar($row['path']) ? (string) $row['path'] : '';
                $extTab = explode('.', $row_path);

                // represent

                // categories

                $query = '
    SELECT category_id, uppercats, dir
      FROM ' . Tables::imageCategory() . ' AS ic
        INNER JOIN ' . Tables::categories() . ' AS c
          ON c.id = ic.category_id
      WHERE image_id = ' . $row_id_str . '
    ;';

                $related_categories = [];
                $related_category_ids = [];
                $media = [
                    'image' => $imageService->getImageInfos($row_id, $htmlRenderer, true),
                ];
                // die_on_missing=true means getImageInfos() only returns null via
                // a fatal_error() path that never returns.
                assert($media['image'] !== null);

                foreach ($conn->fetchAllAssociative($query) as $item) {
                    // Tables::imageCategory()/Tables::categories().category_id/uppercats are
                    // NOT NULL; this guard only defends against the generic mixed
                    // element type a fetched row carries for every column.
                    if ($item['category_id'] === null || $item['uppercats'] === null
                        || (! is_int($item['category_id']) && ! is_string($item['category_id']))
                        || ! is_string($item['uppercats'])) {
                        continue;
                    }
                    $item_category_id = $item['category_id'];

                    $name =
                      $htmlRenderer->getCatDisplayNameCache(
                          $item['uppercats'],
                          get_root_url() . 'admin.php?page=album-'
                      );

                    if ($item_category_id === $storage_category_id) {
                        $template->assign('STORAGE_CATEGORY', $name);
                    }

                    $related_categories[$item_category_id] = [
                        'name' => $name,
                        'unlinkable' => $item_category_id !== $storage_category_id,
                    ];
                    $related_category_ids[] = $item_category_id;
                }

                // jump to link
                $image_file = $row['file'];

                $query = '
    SELECT category_id
    FROM ' . Tables::imageCategory() . '
    WHERE image_id = ' . $row_id_str . '
    ;';
                $currentUser = \Piwigo\Users\CurrentUser::get();
                // array_column() over the fetched rows gives list<mixed> here since
                // only the 'category_id' column is selected; drop non-scalar rows
                // then stringify, since DBAL can hand back native ints for this
                // column (mysqli always gave a numeric string).
                $authorizeds = array_diff(
                    array_map(
                        strval(...),
                        array_filter(
                            array_column($conn->fetchAllAssociative($query), 'category_id'),
                            static fn (mixed $v): bool => is_int($v) || is_string($v)
                        )
                    ),
                    explode(
                        ',',
                        self::permissionService($conn)
                            ->getForbiddenCategories($currentUser->id, $currentUser->status->value)
                    )
                );

                // ProcessCache::get('cat_names') is populated as
                // array<int|string, array<string, mixed>> by
                // get_cat_display_name_cache() (already called above, for
                // every $item in the while loop, before this point) --
                // matches the established narrowing pattern in
                // Piwigo\Admin\PictureModifyPageRenderer.
                $cat_names_raw = \Piwigo\Core\ProcessCache::get('cat_names');
                $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];

                $row_cat_id_raw = $row['cat_id'] ?? null;
                $row_cat_id = (is_int($row_cat_id_raw) || is_string($row_cat_id_raw)) ? (string) $row_cat_id_raw : null;

                if ($row_cat_id !== null
                and in_array($row_cat_id, $authorizeds, true)) {
                    $url_img = make_picture_url(
                        [
                            'image_id' => $row_id,
                            'image_file' => $image_file,
                            'category' => $cat_names[$row_cat_id],
                        ]
                    );
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = make_picture_url(
                            [
                                'image_id' => $row_id, // utile ?
                                'image_file' => $image_file,
                                'category' => $cat_names[$category],
                            ]
                        );
                        break;
                    }
                }
                $admin_photo_base_url = get_root_url() . 'admin.php?page=photo-' . $row_id_str;
                $admin_url_start = $admin_photo_base_url . '-properties';
                $admin_url_start .= $row_cat_id !== null ? '&amp;cat_id=' . $row_cat_id : '';
                $selected_level = $row['level'] ?? null;
                $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                $row_date_available = is_string($row['date_available']) ? $row['date_available'] : '';
                $row_width = is_scalar($row['width'] ?? null) ? (string) $row['width'] : '';
                $row_height = is_scalar($row['height'] ?? null) ? (string) $row['height'] : '';
                $row_name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
                $row_author = is_scalar($row['author'] ?? null) ? (string) $row['author'] : '';
                $row_comment = is_scalar($row['comment'] ?? null) ? (string) $row['comment'] : '';
                $row_added_by_raw = $row['added_by'] ?? null;
                $row_added_by = (is_int($row_added_by_raw) || is_string($row_added_by_raw)) ? $row_added_by_raw : null;

                $template->append(
                    'elements',
                    array_merge(
                        $row,
                        [
                            'ID' => $row_id,
                            'TN_SRC' => DerivativeImage::url(ImageStdParams::MEDIUM, $src_image),
                            'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),
                            'LEGEND' => $legend,
                            'U_EDIT' => get_root_url() . 'admin.php?page=photo-' . $row_id_str,
                            'NAME' => htmlspecialchars($row_name),
                            'AUTHOR' => htmlspecialchars($row_author),
                            'LEVEL' => ($row['level'] ?? '') !== '' && $row['level'] !== '0' ? $row['level'] : '0',
                            'DESCRIPTION' => htmlspecialchars($row_comment),
                            'DATE_CREATION' => $row['date_creation'],
                            'TAGS' => $tag_selection,
                            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
                            'TITLE' => $htmlRenderer->renderElementName($row),
                            'DIMENSIONS' => $row_width . 'x' . $row_height . ' px',
                            'FORMAT' => ($row_width >= $row_height) ? 1 : 0, // 0:horizontal, 1:vertical
                            'FILESIZE' => l10n('%.2f MB', $row_filesize / 1024),
                            'REGISTRATION_DATE' => \Piwigo\Core\DateHelper::formatDate($row_date_available),
                            'EXT' => l10n('%s file type', end($extTab)),
                            'POST_DATE' => l10n('Added on %s', \Piwigo\Core\DateHelper::formatDate($row_date_available, ['day', 'month', 'year'])),
                            'AGE' => l10n(ucfirst(\Piwigo\Core\DateHelper::timeSince($row_date_available, 'year'))),
                            'ADDED_BY' => l10n('Added by %s', $row_added_by !== null ? ($added_by_username_of[$row_added_by] ?? l10n('N/A')) : l10n('N/A')),
                            'STATS' => l10n('Visited %d times', $row['hit']),
                            'FILE' => l10n('%s', $row['file']),
                            'related_categories' => $related_categories,
                            'related_category_ids' => json_encode($related_category_ids),
                            'U_JUMPTO' => (isset($url_img) and $currentUser->level >= $media['image']['level']) ? $url_img : null,
                            'tag_selection' => $tag_selection,
                            'U_DOWNLOAD' => 'action.php?id=' . $row_id_str . '&amp;part=e&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken() . '&amp;download',
                            'U_HISTORY' => get_root_url() . 'admin.php?page=history&amp;filter_image_id=' . $row_id_str,
                            'U_ACTIVITY' => get_root_url() . 'admin.php?page=user_activity&photo=' . $row_id_str,
                            'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
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

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_element_set_unit');

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
    }
}
