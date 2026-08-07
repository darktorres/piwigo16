<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
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
final class AlbumSubController implements AdminSubControllerInterface
{
    private const array KNOWN_TABS = ['properties', 'sort_order', 'permissions', 'notification'];

    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ElementSetRanksPageRenderer $elementSetRanksPageRenderer,
        private readonly CatPermPageRenderer $catPermPageRenderer,
        private readonly AlbumNotificationPageRenderer $albumNotificationPageRenderer,
        private readonly ActivityService $activityService,
        private readonly CategoryService $categoryService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $query_params = $request->getQueryParams();
        $cat_id_param = $query_params['cat_id'] ?? null;
        $cat_id = is_numeric($cat_id_param) ? (int) $cat_id_param : 0;

        $adminAlbumBaseUrl = $this->urlService->getRootUrl() . 'admin.php?page=album-' . $cat_id;
        $this->coreTabs->setContext(new CoreTabsContext(adminAlbumBaseUrl: $adminAlbumBaseUrl));

        $categoryRow = new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), $this->currentConfig)
            ->findById($cat_id);
        if ($categoryRow === null) {
            $this->htmlRenderer
                ->fatalError('unknown album');
        }
        $category = $categoryRow->toArray();

        $tab_param = $query_params['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, self::KNOWN_TABS, true) ? $tab_param : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('album');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($category['name'], 'get_cat_display_name_cache'));
        $category_name = $nameEvent->categoryName;
        $category_id_display = (string) $category['id'];
        $template->assign([
            'ADMIN_PAGE_TITLE' => $this->lang->t('Edit album') . ' <strong>' . $category_name . '</strong>',
            'ADMIN_PAGE_OBJECT_ID' => '#' . $category_id_display,
        ]);

        if ($tab === 'properties') {
            new CatModifyPageRenderer()
                ->render($this->lang, $this->urlService, $category, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->activityService, $this->categoryService, $this->htmlRenderer);
        } elseif ($tab === 'sort_order') {
            $this->elementSetRanksPageRenderer
                ->render();
        } elseif ($tab === 'permissions') {
            $this->catPermPageRenderer
                ->render($adminAlbumBaseUrl, $category);
        } else {
            $this->albumNotificationPageRenderer
                ->render($adminAlbumBaseUrl, $category);
        }
    }
}
