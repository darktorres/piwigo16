<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Tabsheet;
use Piwigo\Template\TemplateRegistry;

final class MaintenanceController
{
    /** @var list<string> */
    public const array PAGES = [
        'maintenance',
        'maintenance_actions',
        'maintenance_env',
        'maintenance_sys',
        'history',
        'stats',
        'site_manager',
        'site_reader_local',
        'site_update',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'maintenance'         => $this->maintenance(),
            'maintenance_actions' => $this->maintenanceActions(),
            'maintenance_env'     => $this->maintenanceEnv(),
            'maintenance_sys'     => $this->maintenanceSys(),
            'history'             => $this->history(),
            'stats'               => $this->stats(),
            'site_manager'        => $this->siteManager(),
            'site_reader_local'   => $this->siteReaderLocal(),
            'site_update'         => $this->siteUpdate(),
            default               => null,
        };
    }

    private function maintenance(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        check_status(ACCESS_ADMINISTRATOR);

        if (isset($_GET['action'])) {
            check_pwg_token();
        }

        $maint_actions = [
            'derivatives'        => ['icon' => 'icon-trash-1',       'label' => l10n('Delete multiple size images')],
            'lock_gallery'       => ['icon' => 'icon-lock',           'label' => l10n('Lock gallery')],
            'unlock_gallery'     => ['icon' => 'icon-lock',           'label' => l10n('Unlock gallery')],
            'categories'         => ['icon' => 'icon-folder-open',    'label' => l10n('Update albums informations')],
            'images'             => ['icon' => 'icon-info-circled-1', 'label' => l10n('Update photos information')],
            'empty_lounge'       => ['icon' => 'icon-thumbs-up',      'label' => l10n('Empty lounge')],
            'delete_orphan_tags' => ['icon' => 'icon-tags',           'label' => l10n('Delete orphan tags')],
            'user_cache'         => ['icon' => 'icon-user-1',         'label' => l10n('Purge user cache')],
            'history_detail'     => ['icon' => 'icon-back-in-time',   'label' => l10n('Purge history detail')],
            'history_summary'    => ['icon' => 'icon-back-in-time',   'label' => l10n('Purge history summary')],
            'sessions'           => ['icon' => 'icon-th-list',        'label' => l10n('Purge sessions')],
            'feeds'              => ['icon' => 'icon-bell',           'label' => l10n('Purge never used notification feeds')],
            'database'           => ['icon' => 'icon-database',       'label' => l10n('Repair and optimize database')],
            'c13y'               => ['icon' => 'icon-ok',             'label' => l10n('Reinitialize check integrity')],
            'search'             => ['icon' => 'icon-search',         'label' => l10n('Purge search history')],
            'compiled-templates' => ['icon' => 'icon-file-code',      'label' => l10n('Purge compiled templates')],
        ];

        $GLOBALS['maint_actions'] = $maint_actions;

        $my_base_url = get_root_url() . 'admin.php?page=';
        $GLOBALS['my_base_url'] = $my_base_url;

        if (isset($_GET['tab'])) {
            check_input_parameter('tab', $_GET, false, '/^(actions|env|sys)$/');
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'actions';
        } else {
            $page['tab'] = 'actions';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('maintenance');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        match ($page['tab']) {
            'actions' => $this->maintenanceActions(),
            'env'     => $this->maintenanceEnv(),
            'sys'     => $this->maintenanceSys(),
            default   => require PHPWG_ROOT_PATH . 'admin/maintenance_' . $page['tab'] . '.php',
        };

        $tpl->assign(['ADMIN_PAGE_TITLE' => l10n('Maintenance')]);
    }

    private function maintenanceActions(): void
    {
        require PHPWG_ROOT_PATH . 'admin/maintenance_actions.php';
    }

    private function maintenanceEnv(): void
    {
        require PHPWG_ROOT_PATH . 'admin/maintenance_env.php';
    }

    private function maintenanceSys(): void
    {
        require PHPWG_ROOT_PATH . 'admin/maintenance_sys.php';
    }

    private function history(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/history.php';
    }

    private function stats(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_history.inc.php';
        require PHPWG_ROOT_PATH . 'admin/stats.php';
    }

    private function siteManager(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/site_manager.php';
    }

    private function siteReaderLocal(): void
    {
        require PHPWG_ROOT_PATH . 'admin/site_reader_local.php';
    }

    private function siteUpdate(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/site_update.php';
    }
}
