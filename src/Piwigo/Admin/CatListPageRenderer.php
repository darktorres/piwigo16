<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Event\CatListPageRendering;
use Piwigo\Admin\Projection\CatListCategoriesPageContext;
use Piwigo\Admin\Projection\CatListHeaderPageContext;
use Piwigo\Admin\Projection\CatListNbCatsPageContext;
use Piwigo\Admin\Request\CatListRequest;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
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
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * renderer does not call check_status() itself.
 *
 * `CoreTabs::setContext(new CoreTabsContext(myBaseUrl: ...))` below supplies
 * this page's own base URL: `CoreTabs::addCoreTabs()` reads it off the
 * injected CoreTabsContext (`self::context()->myBaseUrl`) for the 'albums'
 * tabsheet case. Without it, this page's "List"/"Permalinks" tab hrefs
 * render as bare `href="albums"` / `href="permalinks"` instead of
 * `admin.php?page=albums` / `admin.php?page=permalinks`.
 */
final readonly class CatListPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private CategoryAdminService $categoryAdminService,
        private ActivityService $activityService,
        private CategoryService $categoryService,
        private HtmlService $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EntityManagerInterface $entityManager,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $categoryService = $this->categoryService;

        $this->eventDispatcher->dispatch(new CatListPageRendering());

        $catListRequest = CatListRequest::fromGlobals($this->inputValidator);

        if ($catListRequest->isCsrfCheckRequired) {
            $this->csrfService
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

        $parent_id = $catListRequest->parentId;

        $categories = [];

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
        $navigation = '<a href="' . $base_url . '">';
        $navigation .= $this->lang->t('Home');
        $navigation .= '</a>';

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select('list', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $nb_cats = $categoryService->countAllCategories();
        $template->assignContext(new CatListNbCatsPageContext($nb_cats));

        // request to delete a virtual category
        if ($catListRequest->deleteId !== null) {
            $categoryService->deleteCategories(
                [$catListRequest->deleteId],
                $this->activityService,
                $this->urlService,
                $this->sessionService,
                $this->eventDispatcher,
                $this->entityManager,
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
        if ($parent_id !== null) {
            // same fallback default as include/config_default.inc.php's
            // \Piwigo\Config\CurrentConfig::levelSeparator() (' / '); see the identical pattern in
            // include/section_init.inc.php.
            $level_separator = $this->currentConfig->levelSeparator;
            $navigation .= $level_separator;

            $navigation .= $this->htmlRenderer
                ->getCatDisplayNameFromId(
                    $parent_id,
                    $base_url . '&amp;parent_id='
                );
        }
        $form_action = $this->urlService->getRootUrl() . 'admin.php?page=cat_list';
        if ($parent_id !== null) {
            $form_action .= '&amp;parent_id=' . $parent_id;
        }
        $sort_orders_checked = array_keys($sort_orders);

        $template->assignContext(new CatListHeaderPageContext(
            adminPageTitle: $this->lang->t('Album list management'),
            categoriesNav: (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            formAction: $form_action,
            pwgToken: $this->csrfService
                ->getToken(),
            sortOrders: $sort_orders,
            sortOrderChecked: array_shift($sort_orders_checked),
        ));

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

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=';

        $tpl_categories = [];

        foreach ($categories as $category) {
            $cat_id = (int) $category['id'];

            $cat_list_url = $base_url . 'cat_list';

            $self_url = $cat_list_url;
            if ($parent_id !== null) {
                $self_url .= '&amp;parent_id=' . $parent_id;
            }

            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($category['name'], 'admin_cat_list'));
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
                $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . $this->csrfService->getToken();
            } else {
                if ($this->currentConfig->enableSynchronization) {
                    $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
                }
            }

            $tpl_categories[] = $tpl_cat;
        }

        $template->assignContext(new CatListCategoriesPageContext(
            parentEditUrl: $parent_id !== null ? $base_url . 'album-' . $parent_id : null,
            categories: $tpl_categories,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'cat_list.latte');
    }
}
