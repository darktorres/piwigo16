<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Template;

/**
 * Ported from admin/albums.php (page slug "albums").
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original albums.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 *
 * cmpCat()/assocToOrderedTree() were top-level functions in the original
 * file with zero external callers (confirmed via a direct grep) -- folded
 * into private (static) methods here, removing the "cannot redeclare
 * function on double-include" risk every prior sub-batch with this shape
 * has already converted away. assocToOrderedTree() stays recursive, now
 * threading $nb_photos_in/$nb_sub_photos/$is_forbidden as real parameters
 * through each recursive call instead of a `global` read (Legacy Coupling
 * Retirement Phase 8, 8g).
 *
 * The tabsheet block below now calls CoreTabs::setContext() instead of a
 * bare `$my_base_url = ...;` assignment -- see CoreTabsContext's own
 * docblock for why CoreTabs::addCoreTabs() can't take this as a real
 * parameter.
 */
final class AlbumsPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $conn = DbConnection::build();
        $categoryService = new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
        );

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $conn->fetchNumeric($query);
        $albums_counter = $row !== false ? $row[0] : 0;

        new \Piwigo\Validation\InputValidator()
            ->validate('parent_id', $_GET, false, ValidationPattern::ID);

        // +-------------------------------------------------------------------+
        // | tabs                                                              |
        // +-------------------------------------------------------------------+

        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('list');
        $tabsheet->assign();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_cats = $row !== false ? $row[0] : 0;
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                         categories auto order                     |
        // +-------------------------------------------------------------------+

        $open_cat = $_GET['parent_id'] ?? -1;

        $sort_orders = [
            'name ASC',
            'name DESC',
            'date_creation DESC',
            'date_creation ASC',
            'date_available DESC',
            'date_available ASC',
            'natural_order DESC',
            'natural_order ASC',
        ];

        if (isset($_POST['simpleAutoOrder']) || isset($_POST['recursiveAutoOrder'])) {

            $post_order = $_POST['order'] ?? null;
            if (! is_string($post_order) || ! in_array($post_order, $sort_orders, true)) {
                new HtmlService()
                    ->fatalError('Invalid sort order');
            }
            new \Piwigo\Validation\InputValidator()
                ->validate('id', $_POST, false, '/^-?\d+$/');

            // check_input_parameter() above fatal_error()s on a non-scalar or
            // non-matching value, but only narrows the type on its own end; $_POST
            // itself is still mixed to PHPStan, so re-derive the validated string here.
            $post_id = $_POST['id'] ?? null;
            $post_id = is_string($post_id) ? $post_id : '';

            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' .
              (($post_id === '-1') ? 'IS NULL' : '= ' . $post_id) . '
;';
            $category_ids_raw = array_column($conn->fetchAllAssociative($query), 'id');
            // 'id' is Tables::categories()'s primary key column, always populated
            // per this driver's fetch convention (native int under DBAL, numeric
            // string under mysqli) -- filter out non-scalar values then stringify
            // so downstream implode()/CategoryService::getSubcatIds() calls
            // get values castable to string.
            $category_ids = array_values(array_map(
                strval(...),
                array_filter($category_ids_raw, static fn (mixed $v): bool => is_int($v) || is_string($v))
            ));

            if (isset($_POST['recursiveAutoOrder'])) {
                $category_ids = $categoryService->getSubcatIds($category_ids);
            }

            $categories = [];
            $sort = [];

            [$order_by_field, $order_by_asc] = explode(' ', $post_order);

            $order_by_date = false;
            // Only ever read below when $order_by_date is true (where it's
            // also assigned) -- declared here so Psalm can see it's always
            // defined by the time the loop reads it.
            $ref_dates = [];
            if (str_starts_with($order_by_field, 'date_')) {
                $order_by_date = true;

                $ref_dates = new CategoryAdminService($categoryService)
                    ->getCategoriesRefDate($category_ids, $order_by_field, $order_by_asc === 'ASC' ? 'min' : 'max');
            }

            $query = '
SELECT id, name, id_uppercat
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
            foreach ($conn->fetchAllAssociative($query) as $cat_row) {
                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $cat_row['name'], 'admin_cat_list');
                $cat_row['name'] = is_string($rendered_name) ? $rendered_name : $cat_row['name'];

                if ($order_by_date) {
                    // id is Tables::categories()'s NOT NULL primary key.
                    $cat_row_id = $cat_row['id'];
                    assert(is_int($cat_row_id) || is_string($cat_row_id));
                    $sort[] = $ref_dates[$cat_row_id];
                } else {
                    $cat_row_name = $cat_row['name'];
                    $sort[] = \Piwigo\Core\StringHelper::removeAccents(is_scalar($cat_row_name) ? (string) $cat_row_name : '');
                }

                $categories[] = [
                    'id' => $cat_row['id'],
                    'id_uppercat' => $cat_row['id_uppercat'],
                ];
            }

            array_multisort(
                $sort,
                $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR,
                $order_by_asc === 'ASC' ? SORT_ASC : SORT_DESC,
                $categories
            );

            $categoryService->saveCategoriesOrder($categories);

            $open_cat = $_POST['id'];
        }

        $template->assign('open_cat', $open_cat);

        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+
        $template->set_filename('albums', 'albums.tpl');

        $template->assign(
            [
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php?page=albums',
            ]
        );

        $template->assign('delay_before_autoOpen', \Piwigo\Config\CurrentConfig::albumMoveDelayBeforeAutoOpening());

        // Known limitation: site-wide only -- Users\PreferencesService
        // could support a real per-user override of the default new-album
        // position, but none exists today.
        $template->assign('POS_PREF', \Piwigo\Config\CurrentConfig::newcatDefaultPosition());

        // +-------------------------------------------------------------------+
        // |                          Album display                            |
        // +-------------------------------------------------------------------+

        // Get all albums
        $query = '
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM ' . Tables::categories() . '
;';

        $allAlbum = $conn->fetchAllAssociative($query);

        // Make an id tree
        $associatedTree = [];

        foreach ($allAlbum as $album) {
            // Read every raw column (still string|null, per this driver's
            // fetch convention) before any reassignment below -- writing a mixed
            // value (trigger_change()'s return) into one offset of a generic
            // array<string, string|null> would otherwise widen every other key's
            // inferred type to mixed for the rest of this iteration.
            $album_uppercats = $album['uppercats'];
            $parents = explode(',', is_scalar($album_uppercats) ? (string) $album_uppercats : '');
            $the_place = &$associatedTree[strval($parents[0])];
            if (! is_array($the_place)) {
                // Matches PHP's own null-to-array autovivification on the offset
                // write below -- made explicit so PHPStan can prove $the_place is
                // array-like at every depth of this dynamically built tree.
                $the_place = [];
            }
            /** @var array<string, mixed> $the_place */
            for ($i = 1; $i < count($parents); $i++) {
                $child_key = strval($parents[$i]);
                if (! is_array($the_place['children'] ?? null)) {
                    $the_place['children'] = [];
                }
                if (! is_array($the_place['children'][$child_key] ?? null)) {
                    $the_place['children'][$child_key] = [];
                }
                $the_place = &$the_place['children'][$child_key];
                /** @var array<string, mixed> $the_place */
            }

            $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $album['name'], 'admin_cat_list');
            $album['name'] = is_string($rendered_name) ? $rendered_name : $album['name'];
            $album_lastmodified = $album['lastmodified'];
            $album['lastmodified'] = \Piwigo\Core\DateHelper::timeSince(is_scalar($album_lastmodified) ? (string) $album_lastmodified : '', 'year');

            $the_place['cat'] = $album;
        }

        // WARNING $user['forbidden_categories'] is 100% reliable only on gallery side because
        // it's a cache variable. On administration side, if you modify public/private status
        // of an album or change permissions, this variable is reset and not recalculated until
        // you open the gallery. As this situation doesn't occur each time you use the
        // administration, it's quite reliable but not as much as on gallery side.
        $forbidden_categories = \Piwigo\Users\CurrentUser::get()->forbiddenCategories;
        $is_forbidden = array_fill_keys(@explode(',', $forbidden_categories), 1);

        $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';

        $nb_photos_in = array_column($conn->fetchAllAssociative($query), 'nb_photos', 'category_id');

        $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;';
        $all_categories = array_column($conn->fetchAllAssociative($query), 'uppercats', 'id');

        $subcats_of = [];

        foreach ($all_categories as $id => $uppercats) {
            $uppercats_str = is_scalar($uppercats) ? (string) $uppercats : '';
            foreach (array_slice(explode(',', $uppercats_str), 0, -1) as $uppercat_id) {
                @$subcats_of[$uppercat_id][] = $id;
            }
        }

        $nb_sub_photos = [];
        foreach ($subcats_of as $cat_id => $subcat_ids) {
            $nb_photos = 0;
            foreach ($subcat_ids as $id) {
                if (isset($nb_photos_in[$id]) && is_numeric($nb_photos_in[$id])) {
                    // COUNT(*) always yields a numeric value (native int under
                    // DBAL, numeric string under mysqli); cast so the accumulator
                    // stays a provably-int running total.
                    $nb_photos += (int) $nb_photos_in[$id];
                }
            }

            $nb_sub_photos[$cat_id] = $nb_photos;
        }

        $template->assign(
            [
                'album_data' => self::assocToOrderedTree($associatedTree, $nb_photos_in, $nb_sub_photos, $is_forbidden),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'nb_albums' => count($allAlbum),
                'ADMIN_PAGE_TITLE' => Lang::t('Albums'),
                'light_album_manager' => ($albums_counter > \Piwigo\Config\CurrentConfig::lightAlbumManagerThreshold()) ? 1 : 0,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                          sending html code                        |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'albums');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function cmpCat(array $a, array $b): int
    {
        return $a['rank'] <=> $b['rank'];
    }

    /**
     * Make an ordered tree.
     *
     * @param array<int|string, mixed> $assocT
     * @param array<int|string, mixed> $nb_photos_in
     * @param array<int|string, int> $nb_sub_photos
     * @param array<int|string, int> $is_forbidden
     * @return array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed}[]|array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed, nb_subcats: int<0, max>, children: mixed}[]
     */
    private static function assocToOrderedTree(array $assocT, array $nb_photos_in, array $nb_sub_photos, array $is_forbidden): array
    {
        $orderedTree = [];

        foreach ($assocT as $cat) {
            if (! is_array($cat) || ! isset($cat['cat']) || ! is_array($cat['cat'])) {
                // Every reachable node of $associatedTree gets its 'cat' key
                // populated from its own category row while the tree is built
                // above (uppercats always ends with the category's own id, and
                // $allAlbum holds every category row) -- but that's an
                // algorithmic invariant, not something guaranteed by the type
                // system, so skip defensively instead of trusting it blindly.
                continue;
            }
            /** @var array<string, mixed> $cat_row */
            $cat_row = $cat['cat'];
            // 'id' is the category primary key (NOT NULL in schema); narrow
            // once here since it's reused below as an array key, which
            // requires a non-null type. DBAL can hand back a native int for
            // this column (mysqli always gave a numeric string), so accept
            // both.
            $cat_row_id = $cat_row['id'];
            $cat_id = (is_int($cat_row_id) || is_string($cat_row_id)) ? (string) $cat_row_id : '';

            $orderedCat = [];
            $orderedCat['rank'] = $cat_row['rank'];
            $orderedCat['name'] = $cat_row['name'];
            $orderedCat['status'] = $cat_row['status'];
            // themes/admin/default/js/albums.js embeds this tree as JSON and
            // later compares node ids against the DOM's `data-id` attribute
            // (always a string, per jQuery's .attr()) via a strict-equality
            // Array.includes() -- a native int here (DBAL) instead of the
            // pre-migration numeric string (mysqli) breaks that comparison,
            // so keep it a string across the DBAL migration.
            $orderedCat['id'] = $cat_id;
            // themes/admin/default/js/albums.js's node.visible == 'false'
            // check is a loose string comparison -- same JSON-wire-format
            // reasoning as $orderedCat['id'] above, kept as the 'true'/
            // 'false' string this tree has always sent, not the tinyint
            // column's own real int|bool runtime type.
            $orderedCat['visible'] = SqlDialect::booleanToString((bool) $cat_row['visible']);
            $orderedCat['uppercats'] = $cat_row['uppercats'];
            $orderedCat['nb_images'] = $nb_photos_in[$cat_id] ?? 0;
            $orderedCat['last_updates'] = $cat_row['lastmodified'];
            $orderedCat['has_not_access'] = isset($is_forbidden[$cat_id]);
            $orderedCat['nb_sub_photos'] = $nb_sub_photos[$cat_id] ?? 0;
            if (isset($cat['children']) && is_array($cat['children'])) {
                // Does not update when moving a node
                $orderedCat['nb_subcats'] = count($cat['children']);
                $orderedCat['children'] = self::assocToOrderedTree($cat['children'], $nb_photos_in, $nb_sub_photos, $is_forbidden);
            }
            array_push($orderedTree, $orderedCat);
        }
        usort($orderedTree, self::cmpCat(...));
        return $orderedTree;
    }
}
