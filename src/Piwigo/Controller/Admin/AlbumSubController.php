<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AlbumNotificationPageRenderer;
use Piwigo\Admin\CatModifyPageRenderer;
use Piwigo\Admin\CatPermPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\ElementSetRanksPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Category\Projection\Category;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Controller\Admin\Projection\AlbumSubControllerPageContext;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/album.php's own tab-dispatch shell (page slug "album").
 * The 4 tab bodies are typed renderers: CatModifyPageRenderer
 * ("properties"), ElementSetRanksPageRenderer ("sort_order"),
 * CatPermPageRenderer ("permissions"), and AlbumNotificationPageRenderer
 * ("notification"). Their write operations go through
 * Piwigo\Admin\Category\CategoryAdminService (setCategoryPermissions(),
 * saveImageOrder()).
 *
 * admin.php's own shared check_input_parameter('tab', ...,
 * '/^[a-zA-Z\d_-]+$/') already blocks path traversal on the 'tab' param;
 * this sub-controller's own 4-value allowlist (with a safe 'properties'
 * fallback for anything else) is additional defense-in-depth.
 */
final readonly class AlbumSubController implements AdminSubControllerInterface
{
    private const array KNOWN_TABS = ['properties', 'sort_order', 'permissions', 'notification'];

    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private ElementSetRanksPageRenderer $elementSetRanksPageRenderer,
        private CatPermPageRenderer $catPermPageRenderer,
        private AlbumNotificationPageRenderer $albumNotificationPageRenderer,
        private ActivityService $activityService,
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        $template = $this->currentTemplate->get();

        $query_params = $request->getQueryParams();
        $cat_id_param = $query_params['cat_id'] ?? null;
        $cat_id = is_numeric($cat_id_param) ? (int) $cat_id_param : 0;

        $adminAlbumBaseUrl = $this->urlService->getRootUrl() . 'admin.php?page=album-' . $cat_id;
        $this->coreTabs->setContext(new CoreTabsContext(adminAlbumBaseUrl: $adminAlbumBaseUrl));

        $category = new CategoryRepository($this->entityManager, $this->currentConfig)
            ->findById($cat_id);
        if (! $category instanceof Category) {
            $this->htmlRenderer
                ->fatalError('unknown album');
        }

        $tab_param = $query_params['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, self::KNOWN_TABS, true) ? $tab_param : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('album');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($category->name, 'get_cat_display_name_cache'));
        $category_name = $nameEvent->categoryName;
        $category_id_display = (string) $category->id->value;
        $template->assignContext(new AlbumSubControllerPageContext(
            adminPageTitle: $this->lang->t('Edit album') . ' <strong>' . $category_name . '</strong>',
            adminPageObjectId: '#' . $category_id_display,
        ));

        if ($tab === 'properties') {
            return new CatModifyPageRenderer()
                ->render($this->lang, $this->urlService, $category, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->csrfService, $this->activityService, $this->categoryService, $this->htmlRenderer, $this->entityManager, $this->renderer);
        }
        if ($tab === 'sort_order') {
            return $this->elementSetRanksPageRenderer
                ->render();
        }
        if ($tab === 'permissions') {
            return $this->catPermPageRenderer
                ->render($adminAlbumBaseUrl, $category);
        }

        return $this->albumNotificationPageRenderer
            ->render($adminAlbumBaseUrl, $category);
    }
}
