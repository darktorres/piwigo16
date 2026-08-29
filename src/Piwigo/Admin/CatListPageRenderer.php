<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Event\CatListPageRendering;
use Piwigo\Admin\Projection\CategoryListRow;
use Piwigo\Admin\Projection\CatListView;
use Piwigo\Admin\Request\CatListRequest;
use Piwigo\Auth\CookieService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Category\Projection\CategoryChildRow;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
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
        private Renderer $renderer,
    ) {}

    public function render(): AdminPageResult
    {
        $categoryService = $this->categoryService;

        $this->eventDispatcher->dispatch(new CatListPageRendering());

        $catListRequest = CatListRequest::fromGlobals($this->inputValidator);

        if ($catListRequest->isCsrfCheckRequired) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

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
        $tabsheet->assign($this->currentTemplate, $this->renderer);

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
                    new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig),
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

        $categories_nav = (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation));

        /** @var array<int, CategoryChildRow> $categories */
        $categories = array_column($categoryService->getChildrenOfParent($parent_id), null, 'id');

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
            $cat_id = $category->id;

            $cat_list_url = $base_url . 'cat_list';

            $self_url = $cat_list_url;
            if ($parent_id !== null) {
                $self_url .= '&amp;parent_id=' . $parent_id;
            }

            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($category->name, 'admin_cat_list'));

            $u_delete = null;
            $u_sync = null;
            if (in_array($category->dir, [null, '', '0'], true)) {
                $u_delete = $self_url . '&amp;delete=' . $cat_id . '&amp;pwg_token=' . $this->csrfService->getToken();
            } elseif ($this->currentConfig->enableSynchronization) {
                $u_sync = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
            }

            $tpl_categories[] = new CategoryListRow(
                name: $nameEvent->categoryName,
                nbPhotos: $nb_photos_in[$cat_id] ?? 0,
                nbSubPhotos: $nb_sub_photos[$cat_id] ?? 0,
                nbSubAlbums: isset($subcats_of[$cat_id]) ? count($subcats_of[$cat_id]) : 0,
                id: $cat_id,
                uJumpto: $this->urlService->makeIndexUrl(
                    [
                        'category' => $category->toArray(),
                    ]
                ),
                uChildren: $cat_list_url . '&amp;parent_id=' . $cat_id,
                uEdit: $base_url . 'album-' . $cat_id,
                uAddPhotosAlbum: $base_url . 'photos_add&amp;album=' . $cat_id,
                uMove: $base_url . 'albums#cat-' . $cat_id,
                isVirtual: in_array($category->dir, [null, '', '0'], true),
                catAdminAccess: $categoryService->catAdminAccess($cat_id, $this->currentUser),
            );
        }

        $adminContent = $this->renderer->render(new CatListView(
            categoriesNav: $categories_nav,
            formAction: $form_action,
            csrfToken: $this->csrfService
                ->getToken(),
            categories: $tpl_categories,
            albumViewSelected: self::albumViewSelected(new CookieService()->getAlbumManagerView()),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Album list management'),
        );
    }

    /**
     * Which layout radio `cat_list.latte` paints checked, from the
     * `pwg_album_manager_view` cookie.
     *
     * Absent, empty or `'0'` means no preference yet and the tile layout
     * wins -- `cat_list.ts` writes `'tile'` on its own next tick. A value
     * that is none of the three checks nothing at all, which is what the
     * template's own `== 'compact'`/`== 'line'`/`== 'tile'` comparisons
     * did with a value they did not recognise.
     */
    private static function albumViewSelected(?string $cookie): ?string
    {
        if ($cookie === null || $cookie === '' || $cookie === '0') {
            return 'tile';
        }

        return in_array($cookie, ['compact', 'line', 'tile'], true) ? $cookie : null;
    }
}
