<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\CookieService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Users\UserRepository;

/**
 * Ported from admin/history.php (page slug "history") -- displays the
 * filtered history lines panel. The actual line listing is fetched
 * client-side via an async ws.php?method=pwg.history.search call
 * (include/ws_functions/pwg.php's ws_history_search(), unchanged, out of
 * this batch's scope); this page only renders the filter form.
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
     * `search` stays on `global $page;` -- a separate, not-yet-retired
     * cluster (see this class's own docblock for `search_id`/`nb_lines`/
     * `start`, all confirmed dead and removed instead).
     */
    public function render(string $pageSlug): void
    {
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $types = array_merge(['none'], \Piwigo\Db\MysqliDb::getEnums(Tables::history(), 'image_type'));

        $display_thumbnails = [
            'no_display_thumbnail' => l10n('No display'),
            'display_thumbnail_classic' => l10n('Classic display'),
            'display_thumbnail_hoverbox' => l10n('Hoverbox display'),
        ];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('filter_ip', $_GET, false, '/^[0-9.]+$/');
        new \Piwigo\Validation\InputValidator()
            ->validate('filter_image_id', $_GET, false, '/^\d+$/');
        new \Piwigo\Validation\InputValidator()
            ->validate('filter_user_id', $_GET, false, '/^\d+$/');

        $template->set_filename('history', 'history.tpl');

        $tabsheet = new tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($pageSlug);
        $tabsheet->assign();

        $template->assign(
            [
                'F_ACTION' => get_root_url() . 'admin.php?page=history',
                'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
            ]
        );

        $form = [];

        // $page['search'] is an unserialize()'d value (see ws_history_search() in
        // include/ws_functions/pwg.php); only provably mixed, so narrow to an
        // array (and its 'fields' sub-array) before reading nested offsets.
        $page_search = $page['search'] ?? null;
        $page_search_fields = is_array($page_search) ? ($page_search['fields'] ?? null) : null;
        $page_search_fields = is_array($page_search_fields) ? $page_search_fields : null;

        if (is_array($page_search)) {
            if (isset($page_search_fields['date-after'])) {
                $form['start'] = $page_search_fields['date-after'];
            }

            if (isset($page_search_fields['date-before'])) {
                $form['end'] = $page_search_fields['date-before'];
            }
        } else {
            // by default, at page load, we want the selected date to be the current
            // date
            $form['start'] = $form['end'] = Env::now()->format('Y-m-d');
            $form['types'] = $types;
            // Hoverbox by default
            $form['display_thumbnail'] =
              new CookieService()
                  ->getCookieVar('display_thumbnail', 'no_display_thumbnail');
        }

        $form_param = [];
        $form_param['ip'] = $_GET['filter_ip'] ?? null;
        $form_param['image_id'] = $_GET['filter_image_id'] ?? null;

        // check_input_parameter() above already validated filter_user_id to be
        // digits-only (pattern '/^\d+$/') when present; is_numeric() here narrows
        // the type for static analysis and falls back to the "no filter" sentinel
        // -1 (matching the pre-existing '-1' convention below) for anything else.
        $form_param['user_id'] = isset($_GET['filter_user_id']) && is_numeric($_GET['filter_user_id'])
            ? (int) $_GET['filter_user_id']
            : -1;

        if (isset($_GET['filter_ip']) or isset($_GET['filter_image_id']) or isset($_GET['filter_user_id'])) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] !== -1) {
            // \Piwigo\Config\Config::userFields() maps generic field names to table-specific DB
            // column names (see include/config_default.inc.php, used for external
            // auth integrations) -- the previous raw query here hardcoded the
            // literal 'id'/'username' column names, unlike sibling admin pages
            // (e.g. admin/batch_manager_unit.php) that already read this mapping.
            /** @var array<string, string> $user_fields */
            $user_fields = \Piwigo\Config\Config::userFields();

            $form_param['user_name'] = new UserRepository(DbConnection::build())
                ->findUsernameById($form_param['user_id'], $user_fields['id'], $user_fields['username']);
            $form_param['user_id'] = $form_param['user_name'] === null ? -1 : $form_param['user_id'];
        }

        $template->assign(
            [
                'USER_ID' => $form_param['user_id'],
                'USER_NAME' => $form_param['user_name'] ?? null,
                'IMAGE_ID' => $form_param['image_id'],
                'IP' => $form_param['ip'],
                'START' => $form['start'] ?? null,
                'END' => $form['end'] ?? null,
            ]
        );

        $template->assign('display_thumbnails', $display_thumbnails);
        $template->assign('display_thumbnail_selected', $form['display_thumbnail'] ?? null);
        $template->assign('guest_id', \Piwigo\Config\Config::guestId());
        $template->assign('ADMIN_PAGE_TITLE', l10n('History'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'history');
    }
}
