<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Tabsheet;
use Piwigo\Template\TemplateRegistry;

final class ExtensionsController
{
    /** @var list<string> */
    public const array PAGES = [
        'plugins',
        'plugins_installed',
        'plugins_new',
        'plugin',
        'themes',
        'themes_installed',
        'themes_new',
        'themes_standard_pages',
        'theme',
        'languages',
        'languages_installed',
        'languages_new',
        'updates',
        'updates_ext',
        'updates_pwg',
        'extend_for_templates',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'plugins'               => $this->plugins(),
            'plugins_installed'     => $this->pluginsInstalled(),
            'plugins_new'           => $this->pluginsNew(),
            'plugin'                => $this->plugin(),
            'themes'                => $this->themes(),
            'themes_installed'      => $this->themesInstalled(),
            'themes_new'            => $this->themesNew(),
            'themes_standard_pages' => $this->themesStandardPages(),
            'theme'                 => $this->theme(),
            'languages'             => $this->languages(),
            'languages_installed'   => $this->languagesInstalled(),
            'languages_new'         => $this->languagesNew(),
            'updates'               => $this->updates(),
            'updates_ext'           => $this->updatesExt(),
            'updates_pwg'           => $this->updatesPwg(),
            'extend_for_templates'  => $this->extendForTemplates(),
            default                 => null,
        };
    }

    private function plugins(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $my_base_url = get_root_url() . 'admin.php?page=plugins';
        $GLOBALS['my_base_url'] = $my_base_url;

        if (isset($_GET['tab'])) {
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'installed';
        } else {
            $page['tab'] = 'installed';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('plugins');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] == 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
        } else {
            match ($page['tab']) {
                'installed' => $this->pluginsInstalled(),
                'new'       => $this->pluginsNew(),
                default     => require PHPWG_ROOT_PATH . 'admin/plugins_' . $page['tab'] . '.php',
            };
        }
    }

    private function pluginsInstalled(): void
    {
        require PHPWG_ROOT_PATH . 'admin/plugins_installed.php';
    }

    private function pluginsNew(): void
    {
        require PHPWG_ROOT_PATH . 'admin/plugins_new.php';
    }

    private function plugin(): void
    {
        require PHPWG_ROOT_PATH . 'admin/plugin.php';
    }

    private function themes(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $my_base_url = get_root_url() . 'admin.php?page=themes';
        $GLOBALS['my_base_url'] = $my_base_url;

        if (isset($_GET['tab'])) {
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'installed';
        } else {
            $page['tab'] = 'installed';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('themes');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] == 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        } else {
            match ($page['tab']) {
                'installed'      => $this->themesInstalled(),
                'new'            => $this->themesNew(),
                'standard_pages' => $this->themesStandardPages(),
                default          => require PHPWG_ROOT_PATH . 'admin/themes_' . $page['tab'] . '.php',
            };
        }
    }

    private function themesInstalled(): void
    {
        require PHPWG_ROOT_PATH . 'admin/themes_installed.php';
    }

    private function themesNew(): void
    {
        require PHPWG_ROOT_PATH . 'admin/themes_new.php';
    }

    private function themesStandardPages(): void
    {
        require PHPWG_ROOT_PATH . 'admin/themes_standard_pages.php';
    }

    private function theme(): void
    {
        require PHPWG_ROOT_PATH . 'admin/theme.php';
    }

    private function languages(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $my_base_url = get_root_url() . 'admin.php?page=languages';
        $GLOBALS['my_base_url'] = $my_base_url;

        if (isset($_GET['tab'])) {
            check_input_parameter('tab', $_GET, false, '/^(installed|update|new)$/');
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'installed';
        } else {
            $page['tab'] = 'installed';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('languages');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] == 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        } else {
            match ($page['tab']) {
                'installed' => $this->languagesInstalled(),
                'new'       => $this->languagesNew(),
                default     => require PHPWG_ROOT_PATH . 'admin/languages_' . $page['tab'] . '.php',
            };
        }
    }

    private function languagesInstalled(): void
    {
        require PHPWG_ROOT_PATH . 'admin/languages_installed.php';
    }

    private function languagesNew(): void
    {
        require PHPWG_ROOT_PATH . 'admin/languages_new.php';
    }

    private function updates(): void
    {
        require PHPWG_ROOT_PATH . 'admin/updates.php';
    }

    private function updatesExt(): void
    {
        require PHPWG_ROOT_PATH . 'admin/updates_ext.php';
    }

    private function updatesPwg(): void
    {
        require PHPWG_ROOT_PATH . 'admin/updates_pwg.php';
    }

    private function extendForTemplates(): void
    {
        require PHPWG_ROOT_PATH . 'admin/extend_for_templates.php';
    }
}
