<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Request\CatListRequest;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Location\LocBeginCatList;
use Piwigo\Event\Location\LocEndCatList;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;

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
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly ActivityService $activityService,
        private readonly CategoryService $categoryService,
        private readonly HtmlService $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $categoryService = $this->categoryService;

        $this->eventDispatcher->dispatchNotify(new LocBeginCatList());

        $catListRequest = CatListRequest::fromGlobals($this->inputValidator);

        if ($catListRequest->isCsrfCheckRequired) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $sort_orders = [
            'name ASC' => $this->lang->t('Album name, A &rarr; Z'),
            'name DESC' => $this->lang->t('Album name, Z &rarr; A'),
            'date_creation DESC' => $this->lang->t('Date created, new &rarr; old') . ' ' . $this->lang->t('(determined from photos)'),
            'date_creation ASC' => $this->lang->t('Date created, old &rarr; new') . ' ' . $this->lang->t('(determined from photos)'),
            'date_available DESC' => $this->lang->t('Date posted, new &rarr; old') . ' ' . $this->lang->t('(determined from photos)'),
            'date_available ASC' => $this->lang->t('Date posted, old &rarr; new') . ' ' . $this->lang->t('(determined from photos)'),
        ];

        // +-------------------------------------------------------------------+
        // |                            initialization                          |
        // +-------------------------------------------------------------------+

        $parent_id = $catListRequest->parentId;

        $categories = [];

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
        $navigation = '<a href="' . $base_url . '">';
        $navigation .= $this->lang->t('Home');
        $navigation .= '</a>';

        // +-------------------------------------------------------------------+
        // | tabs                                                              |
        // +-------------------------------------------------------------------+

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('list', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $nb_cats = $categoryService->countAllCategories();
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
                $this->activityService,
                $this->urlService,
                $this->sessionService,
                $this->eventDispatcher,
                $catListRequest->photoDeletionMode
            );

            $_SESSION['page_infos'] = [$this->lang->t('Virtual album deleted')];
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
            $output_create = $this->categoryAdminService
                ->createVirtualCategory(
                    $virtual_name,
                    $this->activityService,
                    $this->currentUser,
                    $parent_id
                );

            PermissionCacheInvalidator::invalidate();
            $output_create_message = is_string($output_create->message) ? $output_create->message : '';
            if (! $output_create->success) {
                $this->pageState->addError($output_create_message);
            } else {
                $edit_url = $this->urlService->getRootUrl() . 'admin.php?page=album-' . (string) $output_create->categoryId;
                $this->pageState->addInfo($output_create_message . ' <a class="icon-pencil" href="' . $edit_url . '">' . $this->lang->t('Edit album') . '</a>');
            }
        }
        // +-------------------------------------------------------------------+
        // |                            Navigation path                        |
        // +-------------------------------------------------------------------+

        if ($parent_id !== null) {
            // same fallback default as include/config_default.inc.php's
            // \Piwigo\Config\CurrentConfig::levelSeparator() (' / '); see the identical pattern in
            // include/section_init.inc.php.
            $level_separator = $this->currentConfig->levelSeparator();
            $navigation .= $level_separator;

            $navigation .= $this->htmlRenderer
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
            'ADMIN_PAGE_TITLE' => $this->lang->t('Album list management'),
            'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            'F_ACTION' => $form_action,
            'PWG_TOKEN' => new CsrfService($this->currentConfig)
                ->getToken(),
            'sort_orders' => $sort_orders,
            'sort_order_checked' => array_shift($sort_orders_checked),
        ]);

        // +-------------------------------------------------------------------+
        // |                          Categories display                       |
        // +-------------------------------------------------------------------+

        $categories = [];

        $categories = array_column($categoryService->getChildrenOfParent($parent_id), null, 'id');
        /** @var array<int|string, array{id: int|string, name: string, permalink: ?string, dir: ?string, rank: int|string|null, status: string}> $categories */

        // get the categories containing images directly
        $categories_with_images = [];
        $nb_photos_in = [];
        $subcats_of = [];
        $nb_sub_photos = [];
        if ((bool) count($categories)) {
            $nb_photos_in = $categoryService->getPhotoCountsByCategory();

            $all_categories = $categoryService->getAllCategoryUppercats();
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
                    if (isset($nb_photos_in[$id])) {
                        $nb_photos += $nb_photos_in[$id];
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

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($category['name'], 'admin_cat_list'));
            $tpl_cat =
              [
                  'NAME' => $nameEvent->categoryName,
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
                  'CAT_ADMIN_ACCESS' => $categoryService->catAdminAccess($cat_id, $this->currentUser),
              ];

            if (in_array($category['dir'], [null, '', '0'], true)) {
                $tpl_cat['U_DELETE'] = $self_url . '&amp;delete=' . $cat_id;
                $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken();
            } else {
                if ($this->currentConfig->enableSynchronization()) {
                    $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
                }
            }

            $template->append('categories', $tpl_cat);
        }

        $this->eventDispatcher->dispatchNotify(new LocEndCatList());

        // +-------------------------------------------------------------------+
        // |                          sending html code                        |
        // +-------------------------------------------------------------------+
        $template->assign_var_from_handle('ADMIN_CONTENT', 'categories');
    }
}
