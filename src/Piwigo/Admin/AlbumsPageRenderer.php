<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Template\RenderCategoryName;
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
    public function render(UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher, \Piwigo\Users\CurrentUser $currentUser, \Piwigo\Template\CurrentTemplate $currentTemplate, CategoryAdminService $categoryAdminService, \Piwigo\Category\CategoryService $categoryService): void
    {
        $template = $currentTemplate->get();

        $albums_counter = $categoryService->countAllCategories();

        $albumsRequest = Request\AlbumsRequest::fromGlobals();

        // +-------------------------------------------------------------------+
        // | tabs                                                              |
        // +-------------------------------------------------------------------+

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('list');
        $tabsheet->assign($currentTemplate);

        $nb_cats = $categoryService->countAllCategories();
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                         categories auto order                     |
        // +-------------------------------------------------------------------+

        $open_cat = $albumsRequest->parentId;

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

        if ($albumsRequest->simpleAutoOrder || $albumsRequest->recursiveAutoOrder) {

            $post_order = $albumsRequest->order;
            if (! is_string($post_order) || ! in_array($post_order, $sort_orders, true)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('Invalid sort order');
            }

            $post_id = $albumsRequest->id;

            $category_ids = array_map(
                strval(...),
                $categoryService->getIdsByParent($post_id === '-1' ? null : (int) $post_id)
            );

            if ($albumsRequest->recursiveAutoOrder) {
                $category_ids = array_map(strval(...), $categoryService->getSubcatIds($category_ids));
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

                $ref_dates = $categoryAdminService
                    ->getCategoriesRefDate($category_ids, $order_by_field, $order_by_asc === 'ASC' ? 'min' : 'max');
            }

            foreach ($categoryService->getIdsNamesUppercatsForIds($category_ids) as $cat_row) {
                $nameEvent = $eventDispatcher->dispatchChange(new RenderCategoryName(is_string($cat_row['name']) ? $cat_row['name'] : '', 'admin_cat_list'));
                $cat_row['name'] = $nameEvent->categoryName;

                if ($order_by_date) {
                    // id is Tables::categories()'s NOT NULL primary key.
                    $cat_row_id = $cat_row['id'];
                    assert(is_int($cat_row_id) || is_string($cat_row_id));
                    $sort[] = $ref_dates[$cat_row_id];
                } else {
                    $sort[] = \Piwigo\Core\StringHelper::removeAccents($cat_row['name']);
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

            $open_cat = $albumsRequest->rawId;
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
        $allAlbum = $categoryService->getAllForAlbumTree();

        // Make an id tree
        /**
         * Dynamically built recursive tree, keyed by successive category-id
         * path segments (from `uppercats`) -- each node is either
         * `array{cat: array<string, mixed>}` (a leaf/branch's own category
         * row, set once the loop below reaches it) or
         * `array{children: array<string, self>}` (an intermediate ancestor
         * with no row of its own yet), or both once a later iteration fills
         * in the other half. Genuinely self-referential, so kept as
         * `array<string, mixed>` rather than forced into a shape PHPStan
         * can't express without a named recursive type alias.
         *
         * @var array<string, mixed>
         */
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

            $nameEvent = $eventDispatcher->dispatchChange(new RenderCategoryName(is_string($album['name']) ? $album['name'] : '', 'admin_cat_list'));
            $album['name'] = $nameEvent->categoryName;
            $album_lastmodified = $album['lastmodified'];
            $album['lastmodified'] = \Piwigo\Core\DateHelper::timeSince(is_scalar($album_lastmodified) ? (string) $album_lastmodified : '', 'year');

            $the_place['cat'] = $album;
        }

        // WARNING $user['forbidden_categories'] is 100% reliable only on gallery side because
        // it's a cache variable. On administration side, if you modify public/private status
        // of an album or change permissions, this variable is reset and not recalculated until
        // you open the gallery. As this situation doesn't occur each time you use the
        // administration, it's quite reliable but not as much as on gallery side.
        $forbidden_categories = $currentUser->get()
            ->forbiddenCategories;
        $is_forbidden = array_fill_keys(@explode(',', $forbidden_categories), 1);

        $nb_photos_in = $categoryService->getPhotoCountsByCategory();

        $all_categories = $categoryService->getAllCategoryUppercats();

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
                if (isset($nb_photos_in[$id])) {
                    $nb_photos += $nb_photos_in[$id];
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
     * @param array{rank: int|string|null, ...} $a
     * @param array{rank: int|string|null, ...} $b
     */
    private static function cmpCat(array $a, array $b): int
    {
        return $a['rank'] <=> $b['rank'];
    }

    /**
     * Make an ordered tree.
     *
     * @param array<int|string, mixed> $assocT see $associatedTree's own docblock above
     *   -- only ever iterated by value, so the exact key type (always a
     *   strval()'d string in practice, but is_array()'s own narrowing can't
     *   prove that specifically) doesn't matter here.
     * @param array<int, int|string> $nb_photos_in COUNT(*) values, never null
     * @param array<int|string, int> $nb_sub_photos
     * @param array<int|string, int> $is_forbidden
     * @return list<array{
     *   rank: int|string|null,
     *   name: string,
     *   status: string,
     *   id: string,
     *   visible: string,
     *   uppercats: string,
     *   nb_images: int|string,
     *   last_updates: string,
     *   has_not_access: bool,
     *   nb_sub_photos: int,
     *   nb_subcats?: int<0, max>,
     *   children?: mixed,
     * }> `children` is recursively this same shape one level down -- left
     *   `mixed` rather than forced into a self-referencing shape PHPStan
     *   can't express without a named type alias, for this one field.
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

            $cat_row_rank = $cat_row['rank'];
            $cat_row_name = $cat_row['name'];
            $cat_row_status = $cat_row['status'];
            $cat_row_uppercats = $cat_row['uppercats'];

            $orderedCat = [];
            // 'rank' is nullable in the schema; a native int under DBAL's
            // mysqli driver (MYSQLI_OPT_INT_AND_FLOAT_NATIVE), a numeric
            // string under its pgsql driver -- see DbConnection::params().
            $orderedCat['rank'] = is_int($cat_row_rank) || is_string($cat_row_rank) ? $cat_row_rank : null;
            // 'name'/'status' are NOT NULL string columns; 'name' may also
            // have been rewritten above by the render_category_name filter
            // (EventDispatcher::triggerChange()'s genuinely arbitrary-by-design
            // return), already narrowed there to string-or-original.
            $orderedCat['name'] = is_scalar($cat_row_name) ? (string) $cat_row_name : '';
            $orderedCat['status'] = is_scalar($cat_row_status) ? (string) $cat_row_status : '';
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
            $orderedCat['uppercats'] = is_scalar($cat_row_uppercats) ? (string) $cat_row_uppercats : '';
            $orderedCat['nb_images'] = $nb_photos_in[$cat_id] ?? 0;
            // Always a DateHelper::timeSince() string by the time it reaches
            // here (reassigned above, before $album was stored into the
            // tree) -- re-narrow since $cat_row's own declared type is
            // array<string, mixed>.
            $cat_row_lastmodified = $cat_row['lastmodified'];
            $orderedCat['last_updates'] = is_scalar($cat_row_lastmodified) ? (string) $cat_row_lastmodified : '';
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
