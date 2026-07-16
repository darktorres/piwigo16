<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;

/**
 * Ported from admin/cat_list.php (page slug "cat_list").
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original cat_list.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 *
 * `global $my_base_url;` below is a real bug fix (P23 batch 6j-1), not a
 * mechanical carry-over: the inline tabsheet block below (formerly
 * admin/include/albums_tab.inc.php, folded in P23 batch 8b-5) sets
 * `$my_base_url` via a bare assignment, and without a preceding `global`
 * declaration in this method's own call frame that assignment stays local
 * to this method, invisible to CoreTabs::addCoreTabs()'s own
 * `global $my_base_url;` read for the 'albums' tabsheet case. Verified
 * live that this page's own "List"/"Permalinks" tab hrefs were rendering
 * as bare `href="albums"` / `href="permalinks"` instead of
 * `admin.php?page=albums` / `admin.php?page=permalinks` before this fix.
 */
final class CatListPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $conf, $page, $template;
        global $my_base_url;

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        trigger_notify('loc_begin_cat_list');

        if (! empty($_POST) or isset($_GET['delete'])) {
            check_pwg_token();
        }

        $sort_orders = [
            'name ASC' => l10n('Album name, A &rarr; Z'),
            'name DESC' => l10n('Album name, Z &rarr; A'),
            'date_creation DESC' => l10n('Date created, new &rarr; old') . ' ' . l10n('(determined from photos)'),
            'date_creation ASC' => l10n('Date created, old &rarr; new') . ' ' . l10n('(determined from photos)'),
            'date_available DESC' => l10n('Date posted, new &rarr; old') . ' ' . l10n('(determined from photos)'),
            'date_available ASC' => l10n('Date posted, old &rarr; new') . ' ' . l10n('(determined from photos)'),
        ];

        // +-------------------------------------------------------------------+
        // |                            initialization                          |
        // +-------------------------------------------------------------------+

        (new \Piwigo\Validation\InputValidator())->validate('parent_id', $_GET, false, ValidationPattern::ID);

        // check_input_parameter() already validated (or killed the request) that
        // $_GET['parent_id'], when present, matches ValidationPattern::ID (digits only) -- but
        // it doesn't retype the superglobal, so we still narrow it once here and
        // reuse this variable everywhere below instead of the raw mixed value.
        $parent_id = null;
        if (isset($_GET['parent_id']) and is_numeric($_GET['parent_id'])) {
            $parent_id = (int) $_GET['parent_id'];
        }

        $categories = [];

        $base_url = get_root_url() . 'admin.php?page=cat_list';
        $navigation = '<a href="' . $base_url . '">';
        $navigation .= l10n('Home');
        $navigation .= '</a>';

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
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$nb_cats] = $row;
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                    virtual categories management                  |
        // +-------------------------------------------------------------------+
        // request to delete a virtual category
        if (isset($_GET['delete']) and is_numeric($_GET['delete'])) {
            $photo_deletion_mode = 'no_delete';
            if (isset($_GET['photo_deletion_mode']) and is_string($_GET['photo_deletion_mode'])) {
                $photo_deletion_mode = $_GET['photo_deletion_mode'];
            }
            delete_categories([(int) $_GET['delete']], $photo_deletion_mode);

            $_SESSION['page_infos'] = [l10n('Virtual album deleted')];
            update_global_rank();
            invalidate_user_cache();

            $redirect_url = get_root_url() . 'admin.php?page=cat_list';
            if ($parent_id !== null) {
                $redirect_url .= '&parent_id=' . $parent_id;
            }
            redirect($redirect_url);
        }
        // request to add a virtual category
        elseif (isset($_POST['submitAdd'])) {
            $virtual_name = is_string($_POST['virtual_name'] ?? null) ? $_POST['virtual_name'] : '';
            $output_create = new CategoryAdminService()
                ->createVirtualCategory($virtual_name, $parent_id);

            invalidate_user_cache();
            // $page['errors']/$page['infos'] are always initialized to an array by
            // include/common.inc.php; re-assert it here so PHPStan can prove the
            // pushes below are array-like (same pattern as
            // admin/include/functions.php).
            $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
            $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];
            if (! $output_create->success) {
                $page['errors'][] = $output_create->message;
            } else {
                $edit_url = get_root_url() . 'admin.php?page=album-' . $output_create->categoryId;
                $page['infos'][] = $output_create->message . ' <a class="icon-pencil" href="' . $edit_url . '">' . l10n('Edit album') . '</a>';
            }
        }
        // +-------------------------------------------------------------------+
        // |                            Navigation path                        |
        // +-------------------------------------------------------------------+

        if ($parent_id !== null) {
            // same fallback default as include/config_default.inc.php's
            // $conf['level_separator'] (' / '); see the identical pattern in
            // include/section_init.inc.php.
            $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';
            $navigation .= $level_separator;

            $navigation .= new HtmlService()
                ->getCatDisplayNameFromId(
                    $parent_id,
                    $base_url . '&amp;parent_id='
                );
        }
        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+
        $template->set_filename('categories', 'cat_list.tpl');

        $form_action = PHPWG_ROOT_PATH . 'admin.php?page=cat_list';
        if ($parent_id !== null) {
            $form_action .= '&amp;parent_id=' . $parent_id;
        }
        $sort_orders_checked = array_keys($sort_orders);

        $template->assign([
            'ADMIN_PAGE_TITLE' => l10n('Album list management'),
            'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            'F_ACTION' => $form_action,
            'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
            'sort_orders' => $sort_orders,
            'sort_order_checked' => array_shift($sort_orders_checked),
        ]);

        // +-------------------------------------------------------------------+
        // |                          Categories display                       |
        // +-------------------------------------------------------------------+

        $categories = [];

        $query = '
SELECT id, name, permalink, dir, `rank`, status
  FROM ' . Tables::categories();
        if ($parent_id === null) {
            $query .= '
  WHERE id_uppercat IS NULL';
        } else {
            $query .= '
  WHERE id_uppercat = ' . $parent_id;
        }
        $query .= '
  ORDER BY `rank` ASC
;';
        $categories = query2array($query, 'id');
        /** @var array<int|string, array<string, string|null>> $categories */

        // get the categories containing images directly
        $categories_with_images = [];
        $nb_photos_in = [];
        $subcats_of = [];
        $nb_sub_photos = [];
        if ((bool) count($categories)) {
            $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';
            // WHERE category_id IN ('.implode(',', array_keys($categories)).')

            $nb_photos_in = query2array($query, 'category_id', 'nb_photos');

            $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;';
            $all_categories = query2array($query, 'id', 'uppercats');
            $subcats_of = [];

            foreach ($all_categories as $id => $uppercats) {
                if (! is_string($uppercats)) {
                    continue;
                }
                foreach (array_slice(explode(',', $uppercats), 0, -1) as $uppercat_id) {
                    $subcats_of[(int) $uppercat_id][] = $id;
                }
            }

            $nb_sub_photos = [];
            foreach ($subcats_of as $cat_id => $subcat_ids) {
                $nb_photos = 0;
                foreach ($subcat_ids as $id) {
                    if (isset($nb_photos_in[$id]) and is_numeric($nb_photos_in[$id])) {
                        $nb_photos += (int) $nb_photos_in[$id];
                    }
                }

                $nb_sub_photos[$cat_id] = $nb_photos;
            }
        }

        $template->assign('categories', []);
        $base_url = get_root_url() . 'admin.php?page=';

        if ($parent_id !== null) {
            $template->assign(
                'PARENT_EDIT',
                $base_url . 'album-' . $parent_id
            );
        }

        foreach ($categories as $category) {
            // 'id' is the Tables::categories() primary key (NOT NULL, auto-increment) --
            // it is always a numeric string here; this is a real guard, not dead
            // code, since query2array()'s return type is generically string|null
            // for every column.
            if (! is_numeric($category['id'])) {
                continue;
            }
            $cat_id = (int) $category['id'];

            $cat_list_url = $base_url . 'cat_list';

            $self_url = $cat_list_url;
            if ($parent_id !== null) {
                $self_url .= '&amp;parent_id=' . $parent_id;
            }

            $tpl_cat =
              [
                  'NAME' => trigger_change(
                      'render_category_name',
                      $category['name'],
                      'admin_cat_list'
                  ),
                  'NB_PHOTOS' => $nb_photos_in[$cat_id] ?? 0,
                  'NB_SUB_PHOTOS' => $nb_sub_photos[$cat_id] ?? 0,
                  'NB_SUB_ALBUMS' => isset($subcats_of[$cat_id]) ? count($subcats_of[$cat_id]) : 0,
                  'ID' => $cat_id,
                  'RANK' => is_numeric($category['rank']) ? ((int) $category['rank']) * 10 : 0,

                  'U_JUMPTO' => make_index_url(
                      [
                          'category' => $category,
                      ]
                  ),

                  'U_CHILDREN' => $cat_list_url . '&amp;parent_id=' . $cat_id,
                  'U_EDIT' => $base_url . 'album-' . $cat_id,
                  'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . $cat_id,
                  'U_MOVE' => $base_url . 'albums#cat-' . $cat_id,

                  'IS_VIRTUAL' => empty($category['dir']),
                  'CAT_ADMIN_ACCESS' => cat_admin_access($cat_id),
              ];

            if (empty($category['dir'])) {
                $tpl_cat['U_DELETE'] = $self_url . '&amp;delete=' . $cat_id;
                $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken();
            } else {
                if ((bool) $conf['enable_synchronization']) {
                    $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
                }
            }

            $template->append('categories', $tpl_cat);
        }

        trigger_notify('loc_end_cat_list');

        // +-------------------------------------------------------------------+
        // |                          sending html code                        |
        // +-------------------------------------------------------------------+
        $template->assign_var_from_handle('ADMIN_CONTENT', 'categories');
    }
}
