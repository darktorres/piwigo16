<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\SiteManagerRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Album\GetAdminsSiteLinks;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteEntity;
use Piwigo\Template\CurrentTemplate;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs the "site_manager" admin page. admin.php gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * controller does not duplicate that check.
 *
 * `$tabsheet->select('site_maager')` (missing "n") is intentional:
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'site_update':` branch
 * defines the same misspelled key (`$sheets['site_maager']`), so both sides
 * must match for the correct tab to highlight. Renaming only one side would
 * silently break tab highlighting; renaming both would touch a second file
 * shared by other already-shipped pages for a purely cosmetic gain.
 *
 * `CoreTabs::setContext(new CoreTabsContext(myBaseUrl: ...))` must run
 * before `$tabsheet->select()` below -- `CoreTabs::addCoreTabs()`'s own
 * `case 'site_update':` branch reads that value via
 * `self::contextField(self::context()->myBaseUrl, 'myBaseUrl')` a few
 * lines below, inside `$tabsheet->select()`'s `tabsheet_before_select`
 * event. There is no bare `$my_base_url` variable or `global` statement in
 * this method.
 */
final class SiteManagerSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly CategoryService $categoryService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableSynchronization()) {
            $this->htmlRenderer
                ->fatalError('synchronization is disabled');
        }

        $siteManagerRequest = SiteManagerRequest::fromGlobals();

        if ($siteManagerRequest->requiresCsrfCheck) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $conn = DbConnection::build();

        $template->set_filenames([
            'site_manager' => 'site_manager.tpl',
        ]);

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('site_update');
        // Matches CoreTabs::addCoreTabs()'s own 'site_maager' key -- see
        // this class's own docblock.
        $tabsheet->select('site_maager', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        // +-----------------------------------------------------------------------+
        // |                        new site creation form                         |
        // +-----------------------------------------------------------------------+
        if ($siteManagerRequest->newSiteGalleriesUrl !== null) {
            $galleries_url_input = $siteManagerRequest->newSiteGalleriesUrl;
            $is_remote = $this->urlService->urlIsRemote($galleries_url_input);
            if ($is_remote) {
                $this->htmlRenderer
                    ->fatalError('remote sites not supported');
            }
            $url = preg_replace('/[\/]*$/', '', $galleries_url_input);
            $url .= '/';
            // Anchored to the real install root, not a `./`-relative path
            // -- every real HTTP entry point's cwd is wherever Apache/
            // PHP-FPM started the process (typically the document root,
            // `public/`), not this project's root; a relative galleries_url
            // also never matched any existing site's own absolute value
            // (InstallWizard seeds site 1 as $this->paths->root .
            // 'galleries/', not a relative path -- same convention
            // MetadataService::getSyncMetadata() already documents), so
            // "add a new site" silently produced both a false "directory
            // does not exist" rejection and, had that check been bypassed,
            // an inconsistent DB row relative to every other site.
            if (! str_starts_with($url, '/')) {
                $url = $this->paths->root . $url;
            }

            // site must not exists
            $site_repo = EntityManagerFactory::build($conn)->getRepository(SiteEntity::class);
            if ($site_repo->countByUrl($url) > 0) {
                $this->pageState->addError($this->lang->t('This site already exists') . ' [' . $url . ']');
            }
            if (! $this->pageState->hasErrors()) {
                if (! file_exists($url)) {
                    $this->pageState->addError($this->lang->t('Directory does not exist') . ' [' . $url . ']');
                }
            }

            if (! $this->pageState->hasErrors()) {
                $site_repo->insert($url);
                $this->pageState->addInfo($url . ' ' . $this->lang->t('created'));
            }
        }

        // +-----------------------------------------------------------------------+
        // |                            actions on site                            |
        // +-----------------------------------------------------------------------+
        if ($siteManagerRequest->action !== null and $siteManagerRequest->siteId !== null) {
            $site_id = $siteManagerRequest->siteId;
            $galleries_url = EntityManagerFactory::build($conn)->getRepository(SiteEntity::class)
                ->findGalleriesUrlById($site_id);
            switch ($siteManagerRequest->action) {
                case 'delete':

                    $this->categoryService->deleteSite($site_id, $this->activityService, $this->urlService, $this->sessionService, $this->eventDispatcher, new PermalinkRepository(EntityManagerFactory::build($conn)));
                    $this->pageState->addInfo($galleries_url . ' ' . $this->lang->t('deleted'));
                    break;

            }
        }

        $template->assign(
            [
                'F_ACTION' => $this->urlService->getRootUrl() . 'admin.php' . $this->urlService->getQueryStringDiff(['action', 'site', 'pwg_token']),
                'PWG_TOKEN' => new CsrfService($this->currentConfig)
                    ->getToken(),
                'ADMIN_PAGE_TITLE' => $this->lang->t('Synchronize'),
            ]
        );

        $sites_detail = EntityManagerFactory::build($conn)->getRepository(SiteEntity::class)
            ->findCategoryAndImageCountsBySite();

        foreach (EntityManagerFactory::build($conn)->getRepository(SiteEntity::class)->findAllSites() as $row) {
            $id = (string) $row->id;
            $id_int = $row->id;
            $galleries_url = $row->galleriesUrl;
            $is_remote = $this->urlService->urlIsRemote($galleries_url);
            $base_url = $this->urlService->getRootUrl() . 'admin.php';
            $base_url .= '?page=site_manager';
            $base_url .= '&amp;site=' . $id;
            $base_url .= '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken();
            $base_url .= '&amp;action=';

            $update_url = $this->urlService->getRootUrl() . 'admin.php';
            $update_url .= '?page=site_update';
            $update_url .= '&amp;site=' . $id;

            $tpl_var =
              [
                  'NAME' => $galleries_url,
                  'TYPE' => $this->lang->t($is_remote ? 'Remote' : 'Local'),
                  'CATEGORIES' => $sites_detail[$id_int]['nb_categories'] ?? 0,
                  'IMAGES' => $sites_detail[$id_int]['nb_images'] ?? 0,
                  'U_SYNCHRONIZE' => $update_url,
              ];

            if ((int) $id !== 1) {
                $tpl_var['U_DELETE'] = $base_url . 'delete';
            }

            // $plugin_links is array of array composed of U_HREF, U_HINT & U_CAPTION
            $tpl_var['plugin_links'] = $this->eventDispatcher->dispatchChange(new GetAdminsSiteLinks([], $id, $is_remote))->pluginLinks;

            $template->append('sites', $tpl_var);
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'site_manager');
    }
}
