<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AlbumNotificationPageRenderer;
use Piwigo\Admin\CatModifyPageRenderer;
use Piwigo\Admin\CatPermPageRenderer;
use Piwigo\Admin\ElementSetRanksPageRenderer;
use Piwigo\Admin\tabsheet;
use Piwigo\Db\Tables;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/album.php's own tab-dispatch shell (page slug "album").
 * The 4 tab bodies are typed renderers: CatModifyPageRenderer
 * ("properties", P23 batch 6f) / ElementSetRanksPageRenderer
 * ("sort_order", P23 batch 6f) / CatPermPageRenderer ("permissions", P23
 * batch 6f) / AlbumNotificationPageRenderer ("notification", P23 batch
 * 6f) -- their write operations were extracted into
 * Piwigo\Admin\Category\CategoryAdminService (setCategoryPermissions(),
 * saveImageOrder()) during an earlier batch.
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
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $query_params = $request->getQueryParams();
        $cat_id_param = $query_params['cat_id'] ?? null;
        $cat_id = is_numeric($cat_id_param) ? (int) $cat_id_param : 0;

        $GLOBALS['admin_album_base_url'] = get_root_url() . 'admin.php?page=album-' . $cat_id;

        $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $cat_id . '
;';
        $category = \Piwigo\Db\MysqliDb::fetchAssoc(\Piwigo\Db\MysqliDb::query($query));
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
            new CatModifyPageRenderer()
                ->render();
        } elseif ($tab === 'sort_order') {
            new ElementSetRanksPageRenderer()
                ->render();
        } elseif ($tab === 'permissions') {
            $_GET['cat'] = $cat_id;
            new CatPermPageRenderer()
                ->render();
        } else {
            new AlbumNotificationPageRenderer()
                ->render();
        }
    }
}
