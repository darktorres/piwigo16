<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AlbumNotificationPageRenderer;
use Piwigo\Admin\CatModifyPageRenderer;
use Piwigo\Admin\CatPermPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\ElementSetRanksPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Template\RenderCategoryName;
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

    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Core\PageState $pageState,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $query_params = $request->getQueryParams();
        $cat_id_param = $query_params['cat_id'] ?? null;
        $cat_id = is_numeric($cat_id_param) ? (int) $cat_id_param : 0;

        $adminAlbumBaseUrl = $this->urlService->getRootUrl() . 'admin.php?page=album-' . $cat_id;
        $this->coreTabs->setContext(new CoreTabsContext(adminAlbumBaseUrl: $adminAlbumBaseUrl));

        $categoryRow = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Category\CategoryEntity::class)
            ->findById($cat_id);
        if ($categoryRow === null) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('unknown album');
        }
        $category = $categoryRow->toArray();

        $tab_param = $query_params['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, self::KNOWN_TABS, true) ? $tab_param : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('album');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($category['name'], 'get_cat_display_name_cache'));
        $category_name = $nameEvent->categoryName;
        $category_id_display = (string) $category['id'];
        $template->assign([
            'ADMIN_PAGE_TITLE' => Lang::t('Edit album') . ' <strong>' . $category_name . '</strong>',
            'ADMIN_PAGE_OBJECT_ID' => '#' . $category_id_display,
        ]);

        if ($tab === 'properties') {
            new CatModifyPageRenderer()
                ->render($this->urlService, $category, $this->eventDispatcher, $this->pageState);
        } elseif ($tab === 'sort_order') {
            \Piwigo\Bootstrap\AdminAccessor::elementSetRanksPageRenderer()
                ->render();
        } elseif ($tab === 'permissions') {
            \Piwigo\Bootstrap\AdminAccessor::catPermPageRenderer()
                ->render($adminAlbumBaseUrl, $category);
        } else {
            \Piwigo\Bootstrap\AdminAccessor::albumNotificationPageRenderer()
                ->render($adminAlbumBaseUrl, $category);
        }
    }
}
