<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;

/**
 * Ported from admin/history.php (page slug "history") -- displays the
 * filtered history lines panel. The actual line listing is fetched
 * client-side via an async ws.php?method=pwg.history.search call
 * (Ws\PwgCore::historySearch(), out of this batch's scope); this page
 * only renders the filter form.
 *
 * Legacy Coupling Retirement Track A batch A5.2h: dropped a confirmed-dead
 * `if (isset($page['search_id'])) { ... }` navbar block -- the only write
 * path was `Ws\PwgCore::historySearch()`'s own redirect to
 * `admin.php?page=history&search_id=...`, which is commented out there
 * (confirmed by direct read), so this `isset()` was always false on a
 * real admin.php request. `nb_lines`/`start` had no other reader in this
 * file (both were read only inside that same dead block), so they're
 * gone too, not retargeted.
 */
final class HistoryPageRenderer
{
    /**
     * Legacy Coupling Retirement Track A batch A5.2f: $pageSlug is an
     * explicit param instead of `global $page['page'];` -- the one real
     * caller (HistorySubController) already knows its own fixed page
     * slug statically (it's the only class registered for the
     * 'history' slug in config/admin_pages.php); selects this page's
     * own tab within the shared 'history' tabsheet group (see
     * StatsPageRenderer, its sibling in that same group).
     * `search` (Phase 2 global-residual sweep): also confirmed dead, same
     * as `search_id`/`nb_lines`/`start` above. This docblock's own former
     * justification (`$page['search']` populated by `include/
     * ws_functions/pwg.php`'s `ws_history_search()`) named a file that no
     * longer exists -- it was ported to `Ws\PwgCore::historySearch()`,
     * whose own `$page['search'] = unserialize(...)` write has no
     * `global` declaration at all (a same-named but unrelated local
     * variable), and `ws.php`/`admin.php` are separate HTTP requests
     * regardless, so there was never a real bridge here even in
     * principle.
     */
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\Template\CurrentTemplate $currentTemplate, \Piwigo\Config\CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();
        $conn = DbConnection::build();

        $types = array_merge(['none'], new DbInfo($conn)->getEnums(Tables::history(), 'image_type'));

        $display_thumbnails = [
            'no_display_thumbnail' => $lang->t('No display'),
            'display_thumbnail_classic' => $lang->t('Classic display'),
            'display_thumbnail_hoverbox' => $lang->t('Hoverbox display'),
        ];

        $accessControl->checkStatus(AccessLevel::Administrator);

        $historyFilter = Request\HistoryFilterRequest::fromGlobals();

        $template->set_filename('history', 'history.tpl');

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // linkStart for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered broken relative hrefs.
        $coreTabs->setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($pageSlug);
        $tabsheet->assign($currentTemplate);

        $template->assign(
            [
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php?page=history',
                'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
            ]
        );

        $form = [];

        // by default, at page load, we want the selected date to be the current
        // date
        $form['start'] = $form['end'] = Env::now()->format('Y-m-d');
        $form['types'] = $types;
        // Hoverbox by default
        $form['display_thumbnail'] =
          new CookieService()
              ->getCookieVar('display_thumbnail', 'no_display_thumbnail');

        $form_param = [];
        $form_param['ip'] = $historyFilter->ip;
        $form_param['image_id'] = $historyFilter->imageId;
        $form_param['user_id'] = $historyFilter->userId;

        if ($historyFilter->hasAnyFilter) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] !== -1) {
            $form_param_user_id = \Piwigo\Common\ValueObject\UserId::tryFrom($form_param['user_id']);
            $form_param_username = $form_param_user_id === null ? null : \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Users\UserInfoEntity::class)
                ->findUsernameById($form_param_user_id);
            $form_param['user_name'] = $form_param_username?->value;
            $form_param['user_id'] = $form_param['user_name'] === null ? -1 : $form_param['user_id'];
        }

        $template->assign(
            [
                'USER_ID' => $form_param['user_id'],
                'USER_NAME' => $form_param['user_name'] ?? null,
                'IMAGE_ID' => $form_param['image_id'],
                'IP' => $form_param['ip'],
                'START' => $form['start'],
                'END' => $form['end'],
            ]
        );

        $template->assign('display_thumbnails', $display_thumbnails);
        $template->assign('display_thumbnail_selected', $form['display_thumbnail'] ?? null);
        $template->assign('guest_id', $currentConfig->guestId());
        $template->assign('ADMIN_PAGE_TITLE', $lang->t('History'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'history');
    }
}
