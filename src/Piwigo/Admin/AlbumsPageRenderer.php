<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
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
 * has already converted away. assocToOrderedTree() stays recursive and
 * keeps reading $nb_photos_in/$nb_sub_photos/$is_forbidden via `global`
 * (which binds the true global scope regardless of nesting depth, so this
 * is a pure mechanical port, not a behavior change).
 *
 * The same fix was missing for `$my_base_url` (set by the inline tabsheet
 * block below, formerly the admin/include/albums_tab.inc.php include,
 * folded in P23 batch 8b-5) until P23 batch 6j-1: without
 * `global $my_base_url;` here, that assignment stayed local to this call
 * frame, invisible to CoreTabs::addCoreTabs()'s own `global $my_base_url;` read
 * for the 'albums' tabsheet case -- verified live that this page's own
 * "List"/"Permalinks" tab hrefs were rendering as bare `href="albums"` /
 * `href="permalinks"` instead of `admin.php?page=albums` /
 * `admin.php?page=permalinks`. Fixed here.
 */
final class AlbumsPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        // This method is always invoked from inside an
        // AdminSubControllerInterface::handle() method
        // (Piwigo\Bootstrap\AdminDispatcher), never from real top-level
        // script scope -- a bare `$is_forbidden = ...` / `$nb_photos_in =
        // ...` / `$nb_sub_photos = ...` below would only ever create a
        // variable local to this method's call frame, invisible to
        // assocToOrderedTree() below, which reads all three back via
        // `global $nb_photos_in, $nb_sub_photos, $is_forbidden;`. The
        // explicit `global` here is what actually makes those assignments
        // reach the real global scope that method expects (same fix as
        // admin/include/functions_notification_by_mail.inc.php's
        // $env_nbm).
        global $is_forbidden;
        global $nb_photos_in;
        global $nb_sub_photos;
        // See this class's own docblock -- required before the inline
        // tabsheet block below (P23 batch 6j-1 fix).
        global $my_base_url;

        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
        assert($row !== null);
        [$albums_counter] = $row;

        new \Piwigo\Validation\InputValidator()
            ->validate('parent_id', $_GET, false, ValidationPattern::ID);

        // +-------------------------------------------------------------------+
        // | tabs                                                              |
        // +-------------------------------------------------------------------+

        $page['tab'] = 'list';

        $my_base_url = get_root_url() . 'admin.php?page=';

        $tabsheet = new tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
        assert($row !== null);
        [$nb_cats] = $row;
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
            if (! is_string($post_order) || ! in_array($post_order, $sort_orders)) {
                die('Invalid sort order');
            }
            new \Piwigo\Validation\InputValidator()
                ->validate('id', $_POST, false, '/^-?\d+$/');

            // check_input_parameter() above fatal_error()s on a non-scalar or
            // non-matching value, but only narrows the type on its own end; $_POST
            // itself is still mixed to PHPStan, so re-derive the validated string here.
            $post_id = $_POST['id'] ?? null;
            $post_id = is_scalar($post_id) ? (string) $post_id : '';

            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' .
              (($post_id === '-1') ? 'IS NULL' : '= ' . $post_id) . '
;';
            $category_ids = \Piwigo\Db\MysqliDb::query2Array($query, null, 'id');
            // 'id' is Tables::categories()'s primary key column, always a numeric
            // string per this driver's string|null fetch convention -- filter out
            // the (never-actually-occurring) null case so downstream implode()/
            // get_subcat_ids() calls get values castable to string.
            $category_ids = array_values(array_filter($category_ids, is_string(...)));

            if (isset($_POST['recursiveAutoOrder'])) {
                $category_ids = get_subcat_ids($category_ids);
            }

            $categories = [];
            $sort = [];

            [$order_by_field, $order_by_asc] = explode(' ', $post_order);

            $order_by_date = false;
            if (str_starts_with($order_by_field, 'date_')) {
                $order_by_date = true;

                $ref_dates = new CategoryAdminService()
                    ->getCategoriesRefDate($category_ids, $order_by_field, $order_by_asc === 'ASC' ? 'min' : 'max');
            }

            $query = '
SELECT id, name, id_uppercat
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
            $result = \Piwigo\Db\MysqliDb::query($query);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                /** @var array<string, string|null> $row */
                $rendered_name = trigger_change('render_category_name', $row['name'], 'admin_cat_list');
                $row['name'] = is_string($rendered_name) ? $rendered_name : $row['name'];

                if ($order_by_date) {
                    // id is Tables::categories()'s NOT NULL primary key.
                    assert($row['id'] !== null);
                    $sort[] = $ref_dates[$row['id']];
                } else {
                    $sort[] = \Piwigo\Core\StringHelper::removeAccents((string) $row['name']);
                }

                $categories[] = [
                    'id' => $row['id'],
                    'id_uppercat' => $row['id_uppercat'],
                ];
            }

            array_multisort(
                $sort,
                $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR,
                $order_by_asc === 'ASC' ? SORT_ASC : SORT_DESC,
                $categories
            );

            $categoryConn = DbConnection::build();
            new CategoryService(
                new CategoryRepository($categoryConn),
                new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
            )->saveCategoriesOrder($categories);

            $open_cat = $_POST['id'];
        }

        $template->assign('open_cat', $open_cat);

        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+
        $template->set_filename('albums', 'albums.tpl');

        $template->assign(
            [
                'F_ACTION' => get_root_url() . 'admin.php?page=albums',
            ]
        );

        $template->assign('delay_before_autoOpen', \Piwigo\Config\Config::albumMoveDelayBeforeAutoOpening());

        $template->assign('POS_PREF', \Piwigo\Config\Config::newcatDefaultPosition()); // TODO use user pref if it exists

        // +-------------------------------------------------------------------+
        // |                          Album display                            |
        // +-------------------------------------------------------------------+

        // Get all albums
        $query = '
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM ' . Tables::categories() . '
;';

        $allAlbum = \Piwigo\Db\MysqliDb::query2Array($query);

        // Make an id tree
        $associatedTree = [];

        foreach ($allAlbum as $album) {
            // Read every raw column (still string|null, per this driver's
            // fetch convention) before any reassignment below -- writing a mixed
            // value (trigger_change()'s return) into one offset of a generic
            // array<string, string|null> would otherwise widen every other key's
            // inferred type to mixed for the rest of this iteration.
            $parents = explode(',', (string) $album['uppercats']);
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

            $rendered_name = trigger_change('render_category_name', $album['name'], 'admin_cat_list');
            $album['name'] = is_string($rendered_name) ? $rendered_name : $album['name'];
            $album['lastmodified'] = \Piwigo\Core\DateHelper::timeSince((string) $album['lastmodified'], 'year');

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

        $nb_photos_in = \Piwigo\Db\MysqliDb::query2Array($query, 'category_id', 'nb_photos');

        $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;';
        $all_categories = \Piwigo\Db\MysqliDb::query2Array($query, 'id', 'uppercats');

        $subcats_of = [];

        foreach ($all_categories as $id => $uppercats) {
            foreach (array_slice(explode(',', (string) $uppercats), 0, -1) as $uppercat_id) {
                @$subcats_of[$uppercat_id][] = $id;
            }
        }

        $nb_sub_photos = [];
        foreach ($subcats_of as $cat_id => $subcat_ids) {
            $nb_photos = 0;
            foreach ($subcat_ids as $id) {
                if (isset($nb_photos_in[$id])) {
                    // COUNT(*) always yields a numeric string per this driver's
                    // string|null fetch convention (see \Piwigo\Db\MysqliDb::query2Array()); cast so the
                    // accumulator stays a provably-int running total.
                    $nb_photos += (int) $nb_photos_in[$id];
                }
            }

            $nb_sub_photos[$cat_id] = $nb_photos;
        }

        $template->assign(
            [
                'album_data' => self::assocToOrderedTree($associatedTree),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'nb_albums' => count($allAlbum),
                'ADMIN_PAGE_TITLE' => l10n('Albums'),
                'light_album_manager' => ($albums_counter > \Piwigo\Config\Config::lightAlbumManagerThreshold()) ? 1 : 0,
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
     * @return array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed}[]|array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed, nb_subcats: int<0, max>, children: mixed}[]
     */
    private static function assocToOrderedTree(array $assocT): array
    {
        /**
         * @var array<int|string, string|null>
         */
        global $nb_photos_in;
        /**
         * @var array<int|string, int>
         */
        global $nb_sub_photos;
        /**
         * @var array<int|string, int>
         */
        global $is_forbidden;

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
            /** @var array<string, string|null> $cat_row */
            $cat_row = $cat['cat'];
            // 'id' is the category primary key (NOT NULL in schema); narrow
            // once here since it's reused below as an array key, which
            // requires a non-null type.
            $cat_id = is_string($cat_row['id']) ? $cat_row['id'] : '';

            $orderedCat = [];
            $orderedCat['rank'] = $cat_row['rank'];
            $orderedCat['name'] = $cat_row['name'];
            $orderedCat['status'] = $cat_row['status'];
            $orderedCat['id'] = $cat_row['id'];
            $orderedCat['visible'] = $cat_row['visible'];
            $orderedCat['uppercats'] = $cat_row['uppercats'];
            $orderedCat['nb_images'] = $nb_photos_in[$cat_id] ?? 0;
            $orderedCat['last_updates'] = $cat_row['lastmodified'];
            $orderedCat['has_not_access'] = isset($is_forbidden[$cat_id]);
            $orderedCat['nb_sub_photos'] = $nb_sub_photos[$cat_id] ?? 0;
            if (isset($cat['children']) && is_array($cat['children'])) {
                // Does not update when moving a node
                $orderedCat['nb_subcats'] = count($cat['children']);
                $orderedCat['children'] = self::assocToOrderedTree($cat['children']);
            }
            array_push($orderedTree, $orderedCat);
        }
        usort($orderedTree, self::cmpCat(...));
        return $orderedTree;
    }
}
