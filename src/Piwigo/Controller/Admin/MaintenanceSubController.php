<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\MaintenanceActionsPageRenderer;
use Piwigo\Admin\MaintenanceEnvPageRenderer;
use Piwigo\Admin\MaintenanceSysPageRenderer;
use Piwigo\Admin\tabsheet;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/maintenance.php's own tab-dispatch shell (page slug
 * "maintenance"), folded directly into this controller -- same shape as
 * every prior P23 batch 6 sub-batch's shell folding. Its own tab dispatch
 * is already validated (`/^(actions|env|sys)$/`). admin.php itself already
 * gates every page behind check_status(AccessLevel::Administrator) before
 * dispatch, so the shell's own (redundant) check_status() call is dropped
 * here. The shell's own check_pwg_token() gate for every $_GET['action']
 * IS real and load-bearing (no CSRF gap found in this sub-batch, unlike
 * 6d-6g) -- kept unchanged.
 *
 * Correction (found during 6i-4): `$my_base_url` is NOT dead code, despite
 * this docblock originally claiming so. It's consumed indirectly by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'maintenance':` branch
 * (formerly `admin/include/add_core_tabs.inc.php`'s `add_core_tabs()`,
 * folded in P23 batch 8b-6), read via `global $my_base_url;` when
 * `tabsheet::select()` fires its `tabsheet_before_select` event a few
 * lines below -- dropping it silently degraded every tab href (missing
 * the `admin.php?page=` prefix entirely). Restored here.
 *
 * A prior P21-era pass had already closed SEC-22 (both phpinfo() call
 * sites, in the "actions" and "env" tabs, replaced with Piwigo\Admin\
 * Maintenance\ServerInfoService's curated output) and extracted the raw
 * SQL shared between the "actions" and "env" tabs into Piwigo\Admin\
 * Maintenance\DbMaintenanceRepository, plus the "sys" tab's own
 * activity-log query onto Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames(). This batch (P23 batch 6h) finishes
 * the job that same class's own docblock had already flagged as
 * outstanding: the "actions"/"env" tabs' own ~18-case action-dispatch
 * switch was still duplicated between them (and had drifted while unused
 * -- see Piwigo\Admin\Maintenance\MaintenanceActionDispatcher's own
 * docblock for the 2 real bugs found and fixed by consolidating it there),
 * ports the 3 tab bodies into Piwigo\Admin\MaintenanceActionsPageRenderer/
 * MaintenanceEnvPageRenderer/MaintenanceSysPageRenderer. (That same P21
 * pass had already fixed a real bug in the "actions" tab's own 'search'
 * case -- a dead sprintf(...) statement whose message text was a
 * copy-paste of the 'c13y' case's own -- carried forward unchanged into
 * MaintenanceActionDispatcher, not re-fixed here.)
 */
final class MaintenanceSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Explicit `global` for $maint_actions (assigned as a bare
        // `$maint_actions = [...]` below) so the 3 renderer classes' own
        // `global $maint_actions;` reads see this array -- load-bearing now
        // that the dynamic include is a real method call frame, not a
        // top-level script include.
        global $maint_actions;

        // Consumed by CoreTabs::addCoreTabs()'s own 'maintenance' case via
        // `global $my_base_url;`, triggered synchronously inside
        // tabsheet::select() below -- must be set before that call, not
        // dead code (see this class's own docblock).
        global $my_base_url;
        $my_base_url = get_root_url() . 'admin.php?page=';

        if (isset($_GET['action'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new \Piwigo\Html\HtmlService());
        }

        // +-------------------------------------------------------------------+
        // | Commons parameters                                                    |
        // +-------------------------------------------------------------------+

        $maint_actions = [
            'derivatives' => [
                'icon' => 'icon-trash-1',
                'label' => l10n('Delete multiple size images'),
            ],
            'lock_gallery' => [
                'icon' => 'icon-lock',
                'label' => l10n('Lock gallery'),
            ],
            'unlock_gallery' => [
                'icon' => 'icon-lock',
                'label' => l10n('Unlock gallery'),
            ],
            'categories' => [
                'icon' => 'icon-folder-open',
                'label' => l10n('Update albums informations'),
            ],
            'images' => [
                'icon' => 'icon-info-circled-1',
                'label' => l10n('Update photos information'),
            ],
            'empty_lounge' => [
                'icon' => 'icon-thumbs-up',
                'label' => l10n('Empty lounge'),
            ],
            'delete_orphan_tags' => [
                'icon' => 'icon-tags',
                'label' => l10n('Delete orphan tags'),
            ],
            'user_cache' => [
                'icon' => 'icon-user-1',
                'label' => l10n('Purge user cache'),
            ],
            'history_detail' => [
                'icon' => 'icon-back-in-time',
                'label' => l10n('Purge history detail'),
            ],
            'history_summary' => [
                'icon' => 'icon-back-in-time',
                'label' => l10n('Purge history summary'),
            ],
            'sessions' => [
                'icon' => 'icon-th-list',
                'label' => l10n('Purge sessions'),
            ],
            'feeds' => [
                'icon' => 'icon-bell',
                'label' => l10n('Purge never used notification feeds'),
            ],
            'database' => [
                'icon' => 'icon-database',
                'label' => l10n('Repair and optimize database'),
            ],
            'c13y' => [
                'icon' => 'icon-ok',
                'label' => l10n('Reinitialize check integrity'),
            ],
            'search' => [
                'icon' => 'icon-search',
                'label' => l10n('Purge search history'),
            ],
            'compiled-templates' => [
                'icon' => 'icon-file-code',
                'label' => l10n('Purge compiled templates'),
            ],
        ];

        // +-------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-------------------------------------------------------------------+

        if (isset($_GET['tab'])) {
            new \Piwigo\Validation\InputValidator()
                ->validate('tab', $_GET, false, '/^(actions|env|sys)$/');
            // check_input_parameter() validates the raw value against the pattern
            // above (fatal_error()-ing on anything else) but does not narrow its
            // type for static analysis -- $_GET values are string|array<mixed> at
            // best, so re-check it is a string before trusting it as the tab name.
            $tab_raw = $_GET['tab'];
            $tab = is_string($tab_raw) ? $tab_raw : 'actions';
        } else {
            $tab = 'actions';
        }

        $tabsheet = new tabsheet();
        $tabsheet->set_id('maintenance');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'env') {
            new MaintenanceEnvPageRenderer()
                ->render();
        } elseif ($tab === 'sys') {
            new MaintenanceSysPageRenderer()
                ->render();
        } else {
            new MaintenanceActionsPageRenderer()
                ->render();
        }

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => l10n('Maintenance'),
            ]
        );
    }
}
