<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/site_manager.php (page slug "site_manager"), folded
 * directly into this controller -- same shape as every prior P23 batch 6
 * sub-batch's shell folding. admin.php itself already gates every page
 * behind check_status(AccessLevel::Administrator) before dispatch, so the
 * original file's own (redundant, same level) check_status() call is
 * dropped here, same precedent as MaintenanceSubController/
 * BatchManagerSubController. Its real mutation paths (POST site creation,
 * GET action=delete) were already correctly `check_pwg_token()`-gated --
 * no CSRF gap found in this sub-batch, kept unchanged.
 *
 * `$tabsheet->select('site_maager')` (missing "n") is not a typo to fix
 * here: `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'site_update':`
 * branch (formerly `admin/include/add_core_tabs.inc.php`'s
 * `add_core_tabs()`, folded in P23 batch 8b-6) defines the exact same
 * misspelled key
 * (`$sheets['site_maager']`), so both sides match and the correct tab
 * highlights. Renaming only one side would silently break tab
 * highlighting (the same regression class found in P23 batch 6i-4);
 * renaming both would touch a second file shared by 4 other already-shipped
 * pages for a purely cosmetic gain. Left as-is (P23 batch 6j scoping pass,
 * see `p23/04-batch6j-overview.md`).
 *
 * `global $my_base_url;` is required before the assignment below -- this
 * method is always invoked from inside an AdminSubControllerInterface::
 * handle() call frame (Piwigo\Bootstrap\AdminDispatcher), never real
 * top-level script scope, so a bare `$my_base_url = ...` would only be
 * local to this call frame, invisible to CoreTabs::addCoreTabs()'s own
 * `global $my_base_url;` read a few lines below inside
 * $tabsheet->select() (same fix class as P23 batch 6i-4's cross-batch
 * regression).
 */
final class SiteManagerSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Config\CurrentConfig::enableSynchronization()) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('synchronization is disabled');
        }

        $siteManagerRequest = Request\SiteManagerRequest::fromGlobals();

        if ($siteManagerRequest->requiresCsrfCheck) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        $conn = DbConnection::build();

        $template->set_filenames([
            'site_manager' => 'site_manager.tpl',
        ]);

        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('site_update');
        // Matches CoreTabs::addCoreTabs()'s own 'site_maager' key -- see
        // this class's own docblock.
        $tabsheet->select('site_maager');
        $tabsheet->assign();

        // +-----------------------------------------------------------------------+
        // |                        new site creation form                         |
        // +-----------------------------------------------------------------------+
        if ($siteManagerRequest->newSiteGalleriesUrl !== null) {
            $galleries_url_input = $siteManagerRequest->newSiteGalleriesUrl;
            $is_remote = $this->urlService->urlIsRemote($galleries_url_input);
            if ($is_remote) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('remote sites not supported');
            }
            $url = preg_replace('/[\/]*$/', '', $galleries_url_input);
            $url .= '/';
            if (! (str_starts_with($url, '.'))) {
                $url = './' . $url;
            }

            // site must not exists
            $site_repo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Site\SiteEntity::class);
            if ($site_repo->countByUrl($url) > 0) {
                \Piwigo\Core\PageState::current()->addError(Lang::t('This site already exists') . ' [' . $url . ']');
            }
            if (! \Piwigo\Core\PageState::current()->hasErrors()) {
                if (! file_exists($url)) {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('Directory does not exist') . ' [' . $url . ']');
                }
            }

            if (! \Piwigo\Core\PageState::current()->hasErrors()) {
                $site_repo->insert($url);
                \Piwigo\Core\PageState::current()->addInfo($url . ' ' . Lang::t('created'));
            }
        }

        // +-----------------------------------------------------------------------+
        // |                            actions on site                            |
        // +-----------------------------------------------------------------------+
        if ($siteManagerRequest->action !== null and $siteManagerRequest->siteId !== null) {
            $site_id = $siteManagerRequest->siteId;
            $galleries_url = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Site\SiteEntity::class)
                ->findGalleriesUrlById($site_id);
            switch ($siteManagerRequest->action) {
                case 'delete':

                    \Piwigo\Bootstrap\CoreDomainAccessor::categoryService()->deleteSite($site_id, \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(), $this->urlService);
                    \Piwigo\Core\PageState::current()->addInfo($galleries_url . ' ' . Lang::t('deleted'));
                    break;

            }
        }

        $template->assign(
            [
                'F_ACTION' => $this->urlService->getRootUrl() . 'admin.php' . $this->urlService->getQueryStringDiff(['action', 'site', 'pwg_token']),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'ADMIN_PAGE_TITLE' => Lang::t('Synchronize'),
            ]
        );

        $query = '
SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images
  FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i
  ON c.id=i.storage_category_id
  WHERE c.site_id IS NOT NULL
  GROUP BY c.site_id
;';
        /** @var array<int|string, array<string, string|null>> $sites_detail */
        $sites_detail = array_column($conn->fetchAllAssociative($query), null, 'site_id');

        foreach (\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Site\SiteEntity::class)->findAllSites() as $row) {
            $id = (string) $row->id;
            $galleries_url = $row->galleriesUrl;
            $is_remote = $this->urlService->urlIsRemote($galleries_url);
            $base_url = $this->urlService->getRootUrl() . 'admin.php';
            $base_url .= '?page=site_manager';
            $base_url .= '&amp;site=' . $id;
            $base_url .= '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken();
            $base_url .= '&amp;action=';

            $update_url = $this->urlService->getRootUrl() . 'admin.php';
            $update_url .= '?page=site_update';
            $update_url .= '&amp;site=' . $id;

            $tpl_var =
              [
                  'NAME' => $galleries_url,
                  'TYPE' => Lang::t($is_remote ? 'Remote' : 'Local'),
                  'CATEGORIES' => (int) @$sites_detail[$id]['nb_categories'],
                  'IMAGES' => (int) @$sites_detail[$id]['nb_images'],
                  'U_SYNCHRONIZE' => $update_url,
              ];

            if ((int) $id !== 1) {
                $tpl_var['U_DELETE'] = $base_url . 'delete';
            }

            $plugin_links = [];
            // $plugin_links is array of array composed of U_HREF, U_HINT & U_CAPTION
            $plugin_links =
              \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                  'get_admins_site_links',
                  $plugin_links,
                  $id,
                  $is_remote
              );
            $tpl_var['plugin_links'] = $plugin_links;

            $template->append('sites', $tpl_var);
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'site_manager');
    }
}
