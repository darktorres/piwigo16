<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/album.php's own tab-dispatch shell (page slug "album").
 * The 4 tab bodies (cat_modify.php "properties" / element_set_ranks.php
 * "sort_order" / cat_perm.php "permissions" / album_notification.php
 * "notification") stay legacy `include` glue for their display/template
 * halves -- their write operations were extracted into
 * Piwigo\Admin\Category\CategoryAdminService (setCategoryPermissions(),
 * saveImageOrder()) during this same batch. Same "keep page/template glue
 * inline" split as PhotosAddSubController (P21 Upload batch).
 *
 * admin.php's own shared check_input_parameter('tab', ...,
 * '/^[a-zA-Z\d_-]+$/') already blocks real path traversal on the 'tab'
 * param the same way it does for photos_add's 'section' -- this
 * sub-controller's own 4-value allowlist (with a safe 'properties'
 * fallback for anything else) is real defense-in-depth, not a fix for an
 * actively-exploitable hole (matching PhotosAddSubController's own note).
 */
final class AlbumSubController implements AdminSubControllerInterface
{
    private const array KNOWN_TABS = ['properties', 'sort_order', 'permissions', 'notification'];

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $query_params = $request->getQueryParams();
        $cat_id_param = $query_params['cat_id'] ?? null;
        $cat_id = is_numeric($cat_id_param) ? (int) $cat_id_param : 0;

        $GLOBALS['admin_album_base_url'] = get_root_url() . 'admin.php?page=album-' . $cat_id;

        $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $cat_id . '
;';
        $category = pwg_db_fetch_assoc(pwg_query($query));
        if (! isset($category['id'])) {
            die('unknown album');
        }
        $GLOBALS['category'] = $category;

        $tab_param = $query_params['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, self::KNOWN_TABS, true) ? $tab_param : 'properties';
        $page['tab'] = $tab;

        $tabsheet = new tabsheet();
        $tabsheet->set_id('album');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $category_name = trigger_change('render_category_name', $category['name'], 'get_cat_display_name_cache');
        if (! is_string($category_name)) {
            $category_name = is_string($category['name']) ? $category['name'] : '';
        }
        $template->assign([
            'ADMIN_PAGE_TITLE' => l10n('Edit album') . ' <strong>' . $category_name . '</strong>',
            'ADMIN_PAGE_OBJECT_ID' => '#' . $category['id'],
        ]);

        if ($tab === 'properties') {
            include PHPWG_ROOT_PATH . 'admin/cat_modify.php';
        } elseif ($tab === 'sort_order') {
            include PHPWG_ROOT_PATH . 'admin/element_set_ranks.php';
        } elseif ($tab === 'permissions') {
            $_GET['cat'] = $cat_id;
            include PHPWG_ROOT_PATH . 'admin/cat_perm.php';
        } else {
            include PHPWG_ROOT_PATH . 'admin/album_notification.php';
        }
    }
}
