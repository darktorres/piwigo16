<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Site\SiteRepository;
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
        global $my_base_url;

        if (! \Piwigo\Config\Config::enableSynchronization()) {
            new HtmlService()
                ->fatalError('synchronization is disabled');
        }

        if (! empty($_POST) or isset($_GET['action'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);
        }

        $conn = DbConnection::build();

        $template->set_filenames([
            'site_manager' => 'site_manager.tpl',
        ]);

        $my_base_url = $this->urlService->getRootUrl() . 'admin.php?page=';

        $tabsheet = new tabsheet();
        $tabsheet->set_id('site_update');
        // Matches CoreTabs::addCoreTabs()'s own 'site_maager' key -- see
        // this class's own docblock.
        $tabsheet->select('site_maager');
        $tabsheet->assign();

        // +-----------------------------------------------------------------------+
        // |                        new site creation form                         |
        // +-----------------------------------------------------------------------+
        if (isset($_POST['submit']) and ! empty($_POST['galleries_url']) and is_string($_POST['galleries_url'])) {
            $galleries_url_input = $_POST['galleries_url'];
            $is_remote = $this->urlService->urlIsRemote($galleries_url_input);
            if ($is_remote) {
                new HtmlService()
                    ->fatalError('remote sites not supported');
            }
            $url = preg_replace('/[\/]*$/', '', $galleries_url_input);
            $url .= '/';
            if (! (str_starts_with($url, '.'))) {
                $url = './' . $url;
            }

            // site must not exists
            $site_repo = new SiteRepository($conn);
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
        $site = null;
        if (isset($_GET['site']) and is_numeric($_GET['site'])) {
            $site = $_GET['site'];
        }
        if (isset($_GET['action']) and $site !== null) {
            $site_id = (int) $site;
            $galleries_url = new SiteRepository($conn)
                ->findGalleriesUrlById($site_id);
            switch ($_GET['action']) {
                case 'delete':

                    new CategoryService(
                        new CategoryRepository($conn),
                        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
                    )->deleteSite($site_id, new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)), $this->urlService);
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

        foreach (new SiteRepository($conn)->findAll() as $row) {
            // 'id' and 'galleries_url' are both NOT NULL columns on the sites
            // table (see install/piwigo_structure-mysql.sql), so findAll() never
            // returns null for either of these keys here.
            $id = is_scalar($row['id']) ? (string) $row['id'] : '';
            $galleries_url = is_string($row['galleries_url']) ? $row['galleries_url'] : '';
            $is_remote = $this->urlService->urlIsRemote($galleries_url);
            $base_url = PHPWG_ROOT_PATH . 'admin.php';
            $base_url .= '?page=site_manager';
            $base_url .= '&amp;site=' . $id;
            $base_url .= '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken();
            $base_url .= '&amp;action=';

            $update_url = PHPWG_ROOT_PATH . 'admin.php';
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
