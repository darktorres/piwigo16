<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Ported from admin/cat_list.php (page slug "cat_list").
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original cat_list.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 *
 * `CoreTabs::setContext(new CoreTabsContext(myBaseUrl: ...))` below is a
 * real bug fix (P23 batch 6j-1), not a mechanical carry-over: the inline
 * tabsheet block below (formerly admin/include/albums_tab.inc.php, folded
 * in P23 batch 8b-5) needs this page's own base URL, and
 * CoreTabs::addCoreTabs() reads it off the injected CoreTabsContext
 * (`self::context()->myBaseUrl`) for the 'albums' tabsheet case -- nothing
 * had previously called setContext() with myBaseUrl for this page. Verified
 * live that this page's own "List"/"Permalinks" tab hrefs were rendering
 * as bare `href="albums"` / `href="permalinks"` instead of
 * `admin.php?page=albums` / `admin.php?page=permalinks` before this fix.
 */
final class CatListPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $categoryConn = DbConnection::build();
        $categoryService = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_cat_list');

        $catListRequest = Request\CatListRequest::fromGlobals();

        if ($catListRequest->isCsrfCheckRequired) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        $sort_orders = [
            'name ASC' => Lang::t('Album name, A &rarr; Z'),
            'name DESC' => Lang::t('Album name, Z &rarr; A'),
            'date_creation DESC' => Lang::t('Date created, new &rarr; old') . ' ' . Lang::t('(determined from photos)'),
            'date_creation ASC' => Lang::t('Date created, old &rarr; new') . ' ' . Lang::t('(determined from photos)'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old') . ' ' . Lang::t('(determined from photos)'),
            'date_available ASC' => Lang::t('Date posted, old &rarr; new') . ' ' . Lang::t('(determined from photos)'),
        ];

        // +-------------------------------------------------------------------+
        // |                            initialization                          |
        // +-------------------------------------------------------------------+

        $parent_id = $catListRequest->parentId;

        $categories = [];

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
        $navigation = '<a href="' . $base_url . '">';
        $navigation .= Lang::t('Home');
        $navigation .= '</a>';

        // +-------------------------------------------------------------------+
        // | tabs                                                              |
        // +-------------------------------------------------------------------+

        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('list');
        $tabsheet->assign();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $categoryConn->fetchNumeric($query);
        $nb_cats = $row !== false ? $row[0] : 0;
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                    virtual categories management                  |
        // +-------------------------------------------------------------------+
        // request to delete a virtual category
        if ($catListRequest->deleteId !== null) {
            $categoryService->deleteCategories(
                [$catListRequest->deleteId],
                \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
                $this->urlService,
                $catListRequest->photoDeletionMode
            );

            $_SESSION['page_infos'] = [Lang::t('Virtual album deleted')];
            $categoryService->updateGlobalRank();
            PermissionCacheInvalidator::invalidate();

            $redirect_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
            if ($parent_id !== null) {
                $redirect_url .= '&parent_id=' . $parent_id;
            }
            $this->redirectService->redirect($redirect_url);
        }
        // request to add a virtual category
        elseif ($catListRequest->isSubmitAdd) {
            $virtual_name = $catListRequest->virtualName;
            $output_create = \Piwigo\Bootstrap\AdminAccessor::categoryAdminService()
                ->createVirtualCategory(
                    $virtual_name,
                    \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
                    $parent_id
                );

            PermissionCacheInvalidator::invalidate();
            $output_create_message = is_string($output_create->message) ? $output_create->message : '';
            if (! $output_create->success) {
                \Piwigo\Core\PageState::current()->addError($output_create_message);
            } else {
                $edit_url = $this->urlService->getRootUrl() . 'admin.php?page=album-' . (string) $output_create->categoryId;
                \Piwigo\Core\PageState::current()->addInfo($output_create_message . ' <a class="icon-pencil" href="' . $edit_url . '">' . Lang::t('Edit album') . '</a>');
            }
        }
        // +-------------------------------------------------------------------+
        // |                            Navigation path                        |
        // +-------------------------------------------------------------------+

        if ($parent_id !== null) {
            // same fallback default as include/config_default.inc.php's
            // \Piwigo\Config\CurrentConfig::levelSeparator() (' / '); see the identical pattern in
            // include/section_init.inc.php.
            $level_separator = \Piwigo\Config\CurrentConfig::levelSeparator();
            $navigation .= $level_separator;

            $navigation .= \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->getCatDisplayNameFromId(
                    $parent_id,
                    $base_url . '&amp;parent_id='
                );
        }
        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+
        $template->set_filename('categories', 'cat_list.tpl');

        $form_action = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
        if ($parent_id !== null) {
            $form_action .= '&amp;parent_id=' . $parent_id;
        }
        $sort_orders_checked = array_keys($sort_orders);

        $template->assign([
            'ADMIN_PAGE_TITLE' => Lang::t('Album list management'),
            'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            'F_ACTION' => $form_action,
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
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
        $categories = array_column($categoryConn->fetchAllAssociative($query), null, 'id');
        /** @var array<int|string, array{id: int|string, name: string, permalink: ?string, dir: ?string, rank: int|string|null, status: string}> $categories */

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

            $nb_photos_in = array_column($categoryConn->fetchAllAssociative($query), 'nb_photos', 'category_id');

            $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;';
            $all_categories = array_column($categoryConn->fetchAllAssociative($query), 'uppercats', 'id');
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
        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=';

        if ($parent_id !== null) {
            $template->assign(
                'PARENT_EDIT',
                $base_url . 'album-' . $parent_id
            );
        }

        foreach ($categories as $category) {
            $cat_id = (int) $category['id'];

            $cat_list_url = $base_url . 'cat_list';

            $self_url = $cat_list_url;
            if ($parent_id !== null) {
                $self_url .= '&amp;parent_id=' . $parent_id;
            }

            $tpl_cat =
              [
                  'NAME' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                      'render_category_name',
                      $category['name'],
                      'admin_cat_list'
                  ),
                  'NB_PHOTOS' => $nb_photos_in[$cat_id] ?? 0,
                  'NB_SUB_PHOTOS' => $nb_sub_photos[$cat_id] ?? 0,
                  'NB_SUB_ALBUMS' => isset($subcats_of[$cat_id]) ? count($subcats_of[$cat_id]) : 0,
                  'ID' => $cat_id,
                  'RANK' => is_numeric($category['rank']) ? ((int) $category['rank']) * 10 : 0,

                  'U_JUMPTO' => $this->urlService->makeIndexUrl(
                      [
                          'category' => $category,
                      ]
                  ),

                  'U_CHILDREN' => $cat_list_url . '&amp;parent_id=' . $cat_id,
                  'U_EDIT' => $base_url . 'album-' . $cat_id,
                  'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . $cat_id,
                  'U_MOVE' => $base_url . 'albums#cat-' . $cat_id,

                  'IS_VIRTUAL' => in_array($category['dir'], [null, '', '0'], true),
                  'CAT_ADMIN_ACCESS' => $categoryService->catAdminAccess($cat_id),
              ];

            if (in_array($category['dir'], [null, '', '0'], true)) {
                $tpl_cat['U_DELETE'] = $self_url . '&amp;delete=' . $cat_id;
                $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken();
            } else {
                if (\Piwigo\Config\CurrentConfig::enableSynchronization()) {
                    $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
                }
            }

            $template->append('categories', $tpl_cat);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_cat_list');

        // +-------------------------------------------------------------------+
        // |                          sending html code                        |
        // +-------------------------------------------------------------------+
        $template->assign_var_from_handle('ADMIN_CONTENT', 'categories');
    }
}
