<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Themes;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\ConfigException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\Language\LanguageRepository;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\TemplateRegistry;

final class ExtensionsController
{
    /** @var list<string> */
    public const array PAGES = [
        'plugins', 'plugins_installed', 'plugins_new', 'plugin',
        'themes', 'themes_installed', 'themes_new', 'themes_standard_pages', 'theme',
        'languages', 'languages_installed', 'languages_new',
        'updates', 'updates_ext', 'updates_pwg',
        'extend_for_templates',
    ];

    public function handle(string $page): void
    {
        if ($page === 'plugins')               { $this->plugins(); }
        elseif ($page === 'plugins_installed') { $this->pluginsInstalled(); }
        elseif ($page === 'plugins_new')       { $this->pluginsNew(); }
        elseif ($page === 'plugin')            { $this->plugin(); }
        elseif ($page === 'themes')            { $this->themes(); }
        elseif ($page === 'themes_installed')  { $this->themesInstalled(); }
        elseif ($page === 'themes_new')        { $this->themesNew(); }
        elseif ($page === 'themes_standard_pages') { $this->themesStandardPages(); }
        elseif ($page === 'theme')             { $this->theme(); }
        elseif ($page === 'languages')         { $this->languages(); }
        elseif ($page === 'languages_installed') { $this->languagesInstalled(); }
        elseif ($page === 'languages_new')     { $this->languagesNew(); }
        elseif ($page === 'updates')           { $this->updates(); }
        elseif ($page === 'updates_ext')       { $this->updatesExt(); }
        elseif ($page === 'updates_pwg')       { $this->updatesPwg(); }
        elseif ($page === 'extend_for_templates') { $this->extendForTemplates(); }
    }

    // ── plugins ───────────────────────────────────────────────────────────────

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

        $tab = (string) $page['tab'];
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
        } elseif ($tab === 'installed') {
            $this->pluginsInstalled();
        } elseif ($tab === 'new') {
            $this->pluginsNew();
        } else {
            require PHPWG_ROOT_PATH . 'admin/plugins_' . $tab . '.php';
        }
    }

    // ── plugins_installed ─────────────────────────────────────────────────────

    private function pluginsInstalled(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $tpl->set_filenames(['plugins' => 'plugins_installed.tpl']);

        if (isset($_GET['show_details'])) {
            $show_details = (1 == $_GET['show_details']);
            pwg_set_session_var('plugins_show_details', $show_details);
        } else {
            $show_details = pwg_get_session_var('plugins_show_details', false);
        }

        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'plugins';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr;
        $pwg_token = get_pwg_token();
        $action_url = $base_url . '&amp;plugin=' . '%s' . '&amp;pwg_token=' . $pwg_token;

        $plugins = new Plugins();

        if (isset($_GET['incompatible_plugins'])) {
            $incompatible_plugins_raw = $plugins->get_incompatible_plugins();
            if (false === $incompatible_plugins_raw) { echo json_encode([]); exit; }
            $incompatible_plugins = [];
            foreach ($incompatible_plugins_raw as $plugin => $version) {
                if ($plugin == '~~expire~~') { continue; }
                $incompatible_plugins[] = $plugin;
            }
            echo json_encode($incompatible_plugins);
            exit;
        }

        $plugin_menu_links_deprec = trigger_change('get_admin_plugin_menu_links', []);
        $settings_url_for_plugin_deprec = [];
        foreach ($plugin_menu_links_deprec as $value) {
            if (!is_array($value)) { continue; }
            $vUrl = is_scalar($value['URL'] ?? null) ? (string) $value['URL'] : '';
            if (preg_match('/^admin\.php\?page=plugin-(.*)$/', $vUrl, $matches)) { $settings_url_for_plugin_deprec[$matches[1]] = $vUrl; }
            elseif (preg_match('/^.*section=(.*?)[\/&%].*$/', $vUrl, $matches)) { $settings_url_for_plugin_deprec[$matches[1]] = $vUrl; }
        }

        $plugins->sort_fs_plugins('name');
        $merged_extensions    = $plugins->get_merged_extensions();
        $merged_plugins       = false;
        $tpl_plugins          = [];
        $count_types_plugins  = ['active' => 0, 'inactive' => 0, 'missing' => 0, 'merged' => 0];

        foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
            $incompatPlugins = is_array($_SESSION['incompatible_plugins'] ?? null) ? $_SESSION['incompatible_plugins'] : [];
            if (isset($incompatPlugins[$plugin_id]) && $fs_plugin['version'] != $incompatPlugins[$plugin_id]) {
                unset($_SESSION['incompatible_plugins']);
            }

            $setting_url = '';
            if ($fs_plugin['hasSettings']) {
                $setting_url = 'admin.php?page=plugin-' . $plugin_id;
                if (preg_match('/^piwigo-(videojs|openstreetmap)$/', (string) $plugin_id)) {
                    $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
                }
            }

            $visit_url = str_replace(['http://piwigo.org/ext', 'https://piwigo.org/ext'], PEM_URL, is_scalar($fs_plugin['uri'] ?? null) ? (string) $fs_plugin['uri'] : '');

            $tpl_plugin = [
                'ID'          => $plugin_id,
                'NAME'        => $fs_plugin['name'],
                'VISIT_URL'   => $visit_url,
                'VERSION'     => $fs_plugin['version'],
                'DESC'        => $fs_plugin['description'],
                'AUTHOR'      => $fs_plugin['author'],
                'AUTHOR_URL'  => $fs_plugin['author uri'] ?? null,
                'U_ACTION'    => sprintf($action_url, $plugin_id),
                'SETTINGS_URL' => $setting_url,
            ];

            $tpl_plugin['STATE'] = isset($plugins->db_plugins_by_id[$plugin_id]) ? $plugins->db_plugins_by_id[$plugin_id]['state'] : 'inactive';

            $fsExtId = $fs_plugin['extension'] ?? null;
            if (isset($fsExtId) && (is_string($fsExtId) || is_int($fsExtId)) && isset($merged_extensions[$fsExtId])) {
                ServiceLocator::get(PluginRepository::class)->updateState($plugin_id, 'inactive');
                $tpl_plugin['STATE'] = 'merged';
                $tpl_plugin['DESC']  = l10n('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
                $merged_plugins      = true;
            }

            $pluginState = is_scalar($tpl_plugin['STATE']) ? (string) $tpl_plugin['STATE'] : 'inactive';
            if (isset($count_types_plugins[$pluginState])) { $count_types_plugins[$pluginState]++; }
            $tpl_plugins[] = $tpl_plugin;
        }

        $tpl->append('plugin_states', 'active');
        $tpl->append('plugin_states', 'inactive');
        if ($merged_plugins) { $tpl->append('plugin_states', 'merged'); }

        $missing_plugin_ids = array_diff(array_keys($plugins->db_plugins_by_id), array_keys($plugins->fs_plugins));
        if (count($missing_plugin_ids) > 0) {
            foreach ($missing_plugin_ids as $plugin_id) {
                $tpl_plugins[] = ['NAME' => $plugin_id, 'ID' => $plugin_id, 'VERSION' => $plugins->db_plugins_by_id[$plugin_id]['version'], 'DESC' => l10n('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'), 'U_ACTION' => sprintf($action_url, $plugin_id), 'STATE' => 'missing'];
                $count_types_plugins['missing']++;
            }
            $tpl->append('plugin_states', 'missing');
        }

        $tpl->assign([
            'plugins'              => $tpl_plugins,
            'count_types_plugins'  => $count_types_plugins,
            'PWG_TOKEN'            => $pwg_token,
            'base_url'             => $base_url,
            'show_details'         => $show_details,
            'max_inactive_before_hide' => isset($_GET['show_inactive']) ? 999 : 8,
            'isWebmaster'          => is_webmaster() ? 1 : 0,
            'ADMIN_PAGE_TITLE'     => l10n('Plugins'),
            'view_selector'        => userprefs_get_param('plugin-manager-view', 'classic'),
            'CONF_ENABLE_EXTENSIONS_INSTALL' => Config::enableExtensionsInstall(),
            'page_data_json'       => json_encode([
                'pwg_token'          => $pwg_token,
                'isWebmaster'        => is_webmaster() ? 1 : 0,
                'show_details'       => $show_details,
                'nb_plugin'          => ['all' => $count_types_plugins['active'] + $count_types_plugins['inactive'] + $count_types_plugins['missing'] + $count_types_plugins['merged'], 'active' => $count_types_plugins['active'], 'inactive' => $count_types_plugins['inactive'], 'other' => $count_types_plugins['missing'] + $count_types_plugins['merged']],
                'activate_msg'       => l10n('Do you want to activate anyway?'),
                'cancel_msg'         => l10n('No, I have changed my mind'),
                'confirm_msg'        => l10n('Yes, I am sure'),
                'deactivate_all_msg' => l10n('Deactivate all'),
                'delete_plugin_msg'  => l10n('Are you sure you want to delete the plugin "%s"?'),
                'deleted_plugin_msg' => l10n('Plugin "%s" deleted!'),
                'incompatible_msg'   => l10n('WARNING! This plugin does not seem to be compatible with this version of Piwigo.'),
                'not_webmaster'      => l10n('Webmaster status required'),
                'nothing_found'      => l10n('No plugins found'),
                'plugin_action_error' => l10n('an error happened'),
                'plugin_added_str'   => l10n('Activated'),
                'plugin_deactivated_str' => l10n('Deactivated'),
                'plugin_found'       => l10n('%s plugin found'),
                'plugin_restored_str' => l10n('Restored'),
                'restore_plugin_msg' => l10n('Are you sure you want to restore the plugin "%s"?'),
                'str_restore_def'    => l10n('While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'),
                'uninstall_plugin_msg' => l10n('Are you sure you want to uninstall the plugin "%s"?'),
                'x_plugins_found'    => l10n('%s plugins found'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
    }

    // ── plugins_new ───────────────────────────────────────────────────────────

    private function pluginsNew(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        $tpl->set_filenames(['plugins' => 'plugins_new.tpl']);

        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'plugins_new';
        $tabStr   = is_scalar($page['tab'] ?? null) ? (string) $page['tab'] : '';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr . '&tab=' . $tabStr;

        $plugins = new Plugins();

        if (isset($_GET['revision']) && isset($_GET['extension'])) {
            if (!is_webmaster()) {
                PageState::current()->addError(l10n('Webmaster status is required.'));
            } else {
                check_pwg_token();
                $install_status = $plugins->extract_plugin_files('install', is_string($_GET['revision']) ? $_GET['revision'] : '', is_string($_GET['extension']) ? $_GET['extension'] : '', $plugin_id);
                redirect($base_url . '&installstatus=' . $install_status . '&plugin_id=' . $plugin_id);
            }
        }

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    $activate_url = get_root_url() . 'admin.php?page=plugins&amp;filter=deactivated';
                    PageState::current()->addInfo(l10n('Plugin has been successfully copied'));
                    PageState::current()->addInfo('<a href="' . $activate_url . '">' . l10n('Activate it now') . '</a>');
                    $getPluginId = is_string($_GET['plugin_id'] ?? null) ? $_GET['plugin_id'] : '';
                    if ($getPluginId !== '' && isset($plugins->fs_plugins[$getPluginId])) {
                        pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'install', ['plugin_id' => $getPluginId, 'version' => $plugins->fs_plugins[$getPluginId]['version']]);
                    }
                    break;
                case 'temp_path_error':   PageState::current()->addError(l10n('Can\'t create temporary file.')); break;
                case 'dl_archive_error':  PageState::current()->addError(l10n('Can\'t download archive.')); break;
                case 'archive_error':     PageState::current()->addError(l10n('Can\'t read or extract archive.')); break;
                default:                  PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars(is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : ''))); PageState::current()->addError(l10n('Please check "plugins" folder and sub-folders permissions (CHMOD).'));
            }
        }

        $tpl->assign('order_options', ['date' => l10n('Post date'), 'revision' => l10n('Last revisions'), 'name' => l10n('Name'), 'author' => l10n('Author'), 'downloads' => l10n('Number of downloads')]);

        $beta_test = (isset($_GET['beta-test']) && $_GET['beta-test'] == 'true');

        if ($plugins->get_server_plugins(true, $beta_test)) {
            if (pwg_get_session_var('plugins_new_order') != null) {
                $order_selected = pwg_get_session_var('plugins_new_order');
                $plugins->sort_server_plugins($order_selected);
                $tpl->assign('order_selected', $order_selected);
            } else {
                $plugins->sort_server_plugins('date');
                $tpl->assign('order_selected', 'date');
            }

            foreach ($plugins->server_plugins as $plugin) {
                $ext_desc  = trim(is_scalar($plugin['extension_description'] ?? null) ? (string) $plugin['extension_description'] : '', " \n\r");
                [$small_desc] = explode("\n", wordwrap($ext_desc, 200));
                $revisionId    = is_scalar($plugin['revision_id'] ?? null) ? (string) $plugin['revision_id'] : '';
                $extensionId   = is_scalar($plugin['extension_id'] ?? null) ? (string) $plugin['extension_id'] : '';
                $url_auto_install = htmlentities($base_url) . '&amp;revision=' . $revisionId . '&amp;extension=' . $extensionId . '&amp;pwg_token=' . get_pwg_token();

                $revisionDateRaw    = $plugin['revision_date'] ?? null;
                $rev_date           = date_create(is_string($revisionDateRaw) ? $revisionDateRaw : '');
                $now_date           = date_create();
                $last_revision_diff = ($rev_date !== false && $now_date !== false) ? date_diff($rev_date, $now_date) : new \DateInterval('P0D');

                $certification       = 1;
                $has_compatible_version = false;
                if ($beta_test) {
                    $compatVersions = is_array($plugin['compatible_with_versions'] ?? null) ? $plugin['compatible_with_versions'] : [];
                    foreach ($compatVersions as $vers) {
                        if (get_branch_from_version(is_string($vers) ? $vers : '') == get_branch_from_version(PHPWG_VERSION)) { $has_compatible_version = true; }
                    }
                } else {
                    $has_compatible_version = true;
                }
                if (!$has_compatible_version)              { $certification = -1; }
                elseif ($last_revision_diff->days < 90)   { $certification = 3; }
                elseif ($last_revision_diff->days < 180)  { $certification = 2; }
                elseif ($last_revision_diff->y > 3)       { $certification = 0; }

                $revDateStr = is_string($revisionDateRaw) ? $revisionDateRaw : null;
                $tpl->append('plugins', [
                    'ID'                       => $extensionId,
                    'EXT_NAME'                 => is_scalar($plugin['extension_name'] ?? null) ? $plugin['extension_name'] : '',
                    'EXT_URL'                  => PEM_URL . '/extension_view.php?eid=' . $extensionId,
                    'SMALL_DESC'               => trim($small_desc, " \r\n"),
                    'BIG_DESC'                 => $ext_desc,
                    'VERSION'                  => is_scalar($plugin['revision_name'] ?? null) ? $plugin['revision_name'] : '',
                    'REVISION_DATE'            => preg_replace('/[^0-9]/', '', (string) strtotime($revDateStr ?? '')),
                    'REVISION_FORMATED_DATE'   => format_date($revDateStr, ['day', 'month', 'year']) . ', ' . time_since($revDateStr, 'day'),
                    'AUTHOR'                   => is_scalar($plugin['author_name'] ?? null) ? $plugin['author_name'] : '',
                    'DOWNLOADS'                => $plugin['extension_nb_downloads'] ?? null,
                    'URL_INSTALL'              => $url_auto_install,
                    'CERTIFICATION'            => $certification,
                    'RATING'                   => $plugin['rating_score'] ?? null,
                    'NB_RATINGS'               => $plugin['nb_ratings'] ?? null,
                    'SCREENSHOT'               => is_scalar($plugin['screenshot_url'] ?? null) ? $plugin['screenshot_url'] : '',
                    'TAGS'                     => is_array($plugin['tags'] ?? null) ? $plugin['tags'] : [],
                ]);
            }
        } else {
            PageState::current()->addError(l10n('Can\'t connect to server.'));
        }

        if (!$beta_test && preg_match('/(beta|RC)/', PHPWG_VERSION)) { $tpl->assign('BETA_URL', $base_url . '&amp;beta-test=true'); }
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
        $tpl->assign('BETA_TEST', $beta_test);
        $tpl->assign('page_data_json', json_encode([
            'str_confirm_msg'   => l10n('Yes, I am sure'), 'str_cancel_msg' => l10n('No, I have changed my mind'), 'str_install_title' => l10n('Are you sure you want to install the plugin "%s"?'),
            'str_x_month' => l10n('%d month'), 'str_x_months' => l10n('%d months'), 'str_x_year' => l10n('%d year'), 'str_x_years' => l10n('%d years'),
            'str_from_begining' => l10n('since the beginning'),
            'strs_certification' => ['-1' => l10n('This plugin is incompatible with your version'), '0' => l10n('This plugin have no update since 3 years ! It may be outdated'), '1' => l10n('This plugin has no recent update'), '2' => l10n('This plugin was updated less than 6 months ago'), '3' => l10n('This plugin have been updated recently')],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
    }

    // ── plugin ────────────────────────────────────────────────────────────────

    private function plugin(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $raw_section = $_GET['section'] ?? '';
        $sections = explode('/', is_scalar($raw_section) ? (string) $raw_section : '');
        for ($i = 0; $i < count($sections); $i++) {
            if (empty($sections[$i])) { unset($sections[$i]); $i--; continue; }
            if ($sections[$i] == '..' || !preg_match('/^[a-zA-Z0-9_\.-]+$/', $sections[$i])) {
                throw new ValidationException('invalid section token [' . htmlentities($sections[$i]) . ']');
            }
        }

        if (count($sections) < 2) { throw new ValidationException('Invalid plugin URL'); }
        $plugin_id = $sections[0];
        if (!preg_match('/^[\w-]+$/', $plugin_id)) { throw new ValidationException('Invalid plugin identifier'); }

        /** @var array<string, mixed> $pwg_loaded_plugins */
        $pwg_loaded_plugins = is_array($GLOBALS['pwg_loaded_plugins'] ?? null) ? $GLOBALS['pwg_loaded_plugins'] : [];
        if (!isset($pwg_loaded_plugins[$plugin_id])) { throw new \Piwigo\Exception\AuthException('Invalid URL - plugin ' . $plugin_id . ' not active'); }

        $filename = PHPWG_PLUGINS_PATH . implode('/', $sections);
        if (is_file($filename)) { require_once $filename; }
        else { throw new NotFoundException('Missing file ' . htmlentities($filename)); }
    }

    // ── themes ────────────────────────────────────────────────────────────────

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

        $tab = (string) $page['tab'];
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        } elseif ($tab === 'installed') {
            $this->themesInstalled();
        } elseif ($tab === 'new') {
            $this->themesNew();
        } elseif ($tab === 'standard_pages') {
            $this->themesStandardPages();
        } else {
            require PHPWG_ROOT_PATH . 'admin/themes_' . $tab . '.php';
        }
    }

    // ── themes_installed ──────────────────────────────────────────────────────

    private function themesInstalled(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!is_webmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'themes';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr;
        $themes   = new Themes();

        if (isset($_GET['action']) && isset($_GET['theme'])) {
            $get_action = is_string($_GET['action']) ? $_GET['action'] : '';
            $get_theme  = is_string($_GET['theme']) ? $_GET['theme'] : '';
            $page['errors'] = $themes->perform_action($get_action, $get_theme);
            if (empty($page['errors'])) {
                if ($_GET['action'] == 'activate' || $_GET['action'] == 'deactivate') { $tpl->delete_compiled_templates(); }
                redirect($base_url);
            }
        }

        $themes->sort_fs_themes();
        $default_theme = get_default_theme();
        $db_themes     = $themes->get_db_themes();
        $db_theme_ids  = [];
        foreach ($db_themes as $db_theme) { $db_theme_ids[] = $db_theme['id']; }

        $tpl_themes = [];
        foreach ($themes->fs_themes as $theme_id => $fs_theme) {
            if ($theme_id == 'default' || $theme_id == 'standard_pages') { continue; }
            $tpl_theme = ['ID' => $theme_id, 'NAME' => $fs_theme['name'], 'VISIT_URL' => $fs_theme['uri'], 'VERSION' => $fs_theme['version'], 'DESC' => $fs_theme['description'], 'AUTHOR' => $fs_theme['author'], 'AUTHOR_URL' => $fs_theme['author uri'] ?? null, 'PARENT' => $fs_theme['parent'] ?? null, 'SCREENSHOT' => $fs_theme['screenshot'], 'IS_MOBILE' => $fs_theme['mobile'], 'ADMIN_URI' => $fs_theme['admin_uri'] ?? null];

            if (in_array($theme_id, $db_theme_ids)) {
                $tpl_theme['STATE']      = 'active';
                $tpl_theme['IS_DEFAULT'] = ($theme_id == $default_theme);
                $tpl_theme['DEACTIVABLE'] = true;
                if (count($db_theme_ids) <= 1) { $tpl_theme['DEACTIVABLE'] = false; $tpl_theme['DEACTIVATE_TOOLTIP'] = l10n('Impossible to deactivate this theme, you need at least one theme.'); }
                if ($tpl_theme['IS_DEFAULT']) { $tpl_theme['DEACTIVABLE'] = false; $tpl_theme['DEACTIVATE_TOOLTIP'] = l10n('Impossible to deactivate the default theme.'); }
            } else {
                $tpl_theme['STATE'] = 'inactive';
                if (isset($fs_theme['activable']) && !$fs_theme['activable']) { $tpl_theme['ACTIVABLE'] = false; $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n('This theme was not designed to be directly activated'); }
                else { $tpl_theme['ACTIVABLE'] = true; }
                $missing_parent = $themes->missing_parent_theme($theme_id);
                if (isset($missing_parent)) { $tpl_theme['ACTIVABLE'] = false; $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n('Impossible to activate this theme, the parent theme is missing: %s', $missing_parent); }
                $children = $themes->get_children_themes($theme_id);
                $tpl_theme['DELETABLE'] = true;
                if (count($children) > 0) { $tpl_theme['DELETABLE'] = false; $tpl_theme['DELETE_TOOLTIP'] = l10n('Impossible to delete this theme. Other themes depends on it: %s', implode(', ', $children)); }
            }
            $tpl_themes[] = $tpl_theme;
        }

        usort($tpl_themes, $this->cmpThemes(...));

        $tpl->assign([
            'activate_baseurl'   => $base_url . '&amp;action=activate&amp;theme=',
            'deactivate_baseurl' => $base_url . '&amp;action=deactivate&amp;theme=',
            'set_default_baseurl' => $base_url . '&amp;action=set_default&amp;theme=',
            'delete_baseurl'     => $base_url . '&amp;action=delete&amp;theme=',
            'tpl_themes'         => $tpl_themes,
        ]);

        trigger_notify('loc_end_themes_installed');
        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        $tpl->assign('CONF_ENABLE_EXTENSIONS_INSTALL', Config::enableExtensionsInstall());
        $tpl->assign('page_data_json', json_encode(['str_delete_theme_confirm' => l10n('Are you sure you want to delete the theme "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->set_filenames(['themes' => 'themes_installed.tpl']);
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }

    // ── themes_new ────────────────────────────────────────────────────────────

    private function themesNew(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableExtensionsInstall()) { throw new ConfigException('Piwigo extensions install/update system is disabled'); }

        $tpl->set_filenames(['themes' => 'themes_new.tpl']);

        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'themes_new';
        $tabStr   = is_scalar($page['tab'] ?? null) ? (string) $page['tab'] : '';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr . '&tab=' . $tabStr;
        $themes   = new Themes();

        $themes_dir = PHPWG_ROOT_PATH . 'themes';
        if (!is_writable($themes_dir)) { PageState::current()->addError(l10n('Add write access to the "%s" directory', 'themes')); }

        if (isset($_GET['revision']) && isset($_GET['extension'])) {
            if (!is_webmaster()) { PageState::current()->addError(l10n('Webmaster status is required.')); }
            else {
                check_pwg_token();
                $install_status = $themes->extract_theme_files('install', is_string($_GET['revision']) ? $_GET['revision'] : '', is_string($_GET['extension']) ? $_GET['extension'] : '', $theme_id);
                redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . $theme_id);
            }
        }

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    PageState::current()->addInfo(l10n('Theme has been successfully installed'));
                    $theme_id_str = is_string($_GET['theme_id'] ?? null) ? $_GET['theme_id'] : '';
                    if ($theme_id_str !== '' && isset($themes->fs_themes[$theme_id_str])) { pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'install', ['theme_id' => $theme_id_str, 'version' => $themes->fs_themes[$theme_id_str]['version']]); }
                    break;
                case 'temp_path_error':  PageState::current()->addError(l10n('Can\'t create temporary file.')); break;
                case 'dl_archive_error': PageState::current()->addError(l10n('Can\'t download archive.')); break;
                case 'archive_error':    PageState::current()->addError(l10n('Can\'t read or extract archive.')); break;
                default:                 PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars(is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : '')));
            }
        }

        if ($themes->get_server_themes(true)) {
            foreach ($themes->server_themes as $theme) {
                $theme_revision_id  = is_scalar($theme['revision_id'] ?? null) ? (string) $theme['revision_id'] : '';
                $theme_extension_id = is_scalar($theme['extension_id'] ?? null) ? (string) $theme['extension_id'] : '';
                $url_auto_install   = htmlentities($base_url) . '&amp;revision=' . $theme_revision_id . '&amp;extension=' . $theme_extension_id . '&amp;pwg_token=' . get_pwg_token();
                $tpl->append('new_themes', ['name' => $theme['extension_name'], 'thumbnail' => key_exists('thumbnail_src', $theme) ? $theme['thumbnail_src'] : '', 'screenshot' => key_exists('screenshot_url', $theme) ? $theme['screenshot_url'] : '', 'install_url' => $url_auto_install]);
            }
        } else {
            PageState::current()->addError(l10n('Can\'t connect to server.'));
        }

        $adminTheme = userprefs_get_param('admin_theme', 'roma');
        $tpl->assign('default_screenshot', get_root_url() . 'admin/themes/' . (is_scalar($adminTheme) ? (string) $adminTheme : 'roma') . '/images/missing_screenshot.png');
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }

    // ── themes_standard_pages ─────────────────────────────────────────────────

    private function themesStandardPages(): void
    {
        $tpl = TemplateRegistry::current();

        if (!is_webmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $std_pgs_logo_options = ['piwigo_logo', 'custom_logo', 'gallery_title', 'none'];
        $std_pgs_skin_options = ['default', 'cadmium', 'cobalt', 'fuchsia', 'green', 'lime', 'purple', 'red', 'sienna', 'silver', 'teal'];

        if (isset($_POST['submit']) && is_webmaster()) {
            check_pwg_token();
            conf_update_param('use_standard_pages', !empty($_POST['use_standard_pages']), true);
            if (isset($_POST['std_pgs_display_logo']) && in_array($_POST['std_pgs_display_logo'], $std_pgs_logo_options)) { conf_update_param('standard_pages_selected_logo', $_POST['std_pgs_display_logo'], true); }
            if (isset($_POST['std_pgs_selected_skin']) && in_array($_POST['std_pgs_selected_skin'], $std_pgs_skin_options)) { conf_update_param('standard_pages_selected_skin', $_POST['std_pgs_selected_skin'], true); }
        }

        $std_pgs_logo_file = is_array($_FILES['std_pgs_logo'] ?? null) ? $_FILES['std_pgs_logo'] : [];
        $std_pgs_logo_tmp  = is_string($std_pgs_logo_file['tmp_name'] ?? null) ? (string) $std_pgs_logo_file['tmp_name'] : '';
        if (isset($_FILES['std_pgs_logo']) && !empty($std_pgs_logo_tmp)) {
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = $finfo !== false ? finfo_file($finfo, $std_pgs_logo_tmp) : false;
            $allowed_mimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/svg' => 'svg', 'image/webp' => 'webp'];

            if ($mime_type === false || !in_array($mime_type, array_keys($allowed_mimes))) {
                $tpl->assign(['save_error' => 'Invalid image file.']);
            } else {
                $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'logo';
                if (mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
                    $std_pgs_logo_name = is_scalar($std_pgs_logo_file['name'] ?? null) ? (string) $std_pgs_logo_file['name'] : '';
                    $pathinfo  = pathinfo($std_pgs_logo_name);
                    $file_path = $upload_dir . '/' . str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];
                    conf_update_param('standard_pages_selected_logo_path', $file_path, true);
                    $logoStream = fopen($std_pgs_logo_tmp, 'rb');
                    if ($logoStream !== false) {
                        StorageRegistry::disk('local')->writeStream('logo/' . basename($file_path), $logoStream);
                        fclose($logoStream);
                    } else {
                        $tpl->assign(['save_error' => "$file_path " . l10n('no write access')]);
                    }
                } else {
                    $tpl->assign(['save_error' => sprintf(l10n('Add write access to the "%s" directory'), $upload_dir)]);
                }
            }
        }

        $themes = new Themes();
        $themes->get_fs_themes();
        $is_standard_pages_used = false;
        $standard_pages_used_by = [];
        foreach ($themes->fs_themes as $theme) {
            if (isset($theme['use_standard_pages']) && $theme['use_standard_pages']) { $is_standard_pages_used = true; array_push($standard_pages_used_by, $theme['name']); }
        }

        $tpl->assign([
            'use_standard_pages'            => conf_get_param('use_standard_pages', true),
            'std_pgs_selected_logo'         => conf_get_param('standard_pages_selected_logo', 'piwigo_logo'),
            'std_pgs_logo_options'          => $std_pgs_logo_options,
            'std_pgs_selected_skin'         => conf_get_param('standard_pages_selected_skin', 'default'),
            'std_pgs_skin_options'          => $std_pgs_skin_options,
            'is_standard_pages_used'        => $is_standard_pages_used,
            'standard_pages_used_by'        => $standard_pages_used_by,
            'std_pgs_selected_logo_path'    => conf_get_param('standard_pages_selected_logo_path', null),
            'PWG_TOKEN'                     => get_pwg_token(),
        ]);
        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->set_filenames(['themes' => 'themes_standard_pages.tpl']);
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }

    // ── theme ─────────────────────────────────────────────────────────────────

    private function theme(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        if (empty($_GET['theme'])) { throw new ValidationException('Invalid theme URL'); }

        $themes = new Themes();
        if (!in_array($_GET['theme'], array_keys($themes->fs_themes))) { throw new ValidationException('Invalid theme'); }

        $theme_name = is_scalar($_GET['theme']) ? (string) $_GET['theme'] : '';
        $filename   = PHPWG_THEMES_PATH . $theme_name . '/admin/admin.inc.php';
        if (is_file($filename)) { require_once $filename; }
        else { throw new NotFoundException('Missing file ' . $filename); }
    }

    // ── languages ─────────────────────────────────────────────────────────────

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

        $tab = (string) $page['tab'];
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        } elseif ($tab === 'installed') {
            $this->languagesInstalled();
        } elseif ($tab === 'new') {
            $this->languagesNew();
        } else {
            require PHPWG_ROOT_PATH . 'admin/languages_' . $tab . '.php';
        }
    }

    // ── languages_installed ───────────────────────────────────────────────────

    private function languagesInstalled(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!is_webmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $tpl->set_filenames(['languages' => 'languages_installed.tpl']);
        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'languages';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr;

        $languages = new Languages();
        $languages->get_db_languages();

        check_input_parameter('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
        check_input_parameter('language', $_GET, false, '/^(' . join('|', array_keys($languages->fs_languages)) . ')$/');

        if (isset($_GET['action']) && isset($_GET['language']) && is_webmaster()) {
            $page['errors'] = $languages->perform_action(is_string($_GET['action']) ? $_GET['action'] : '', is_string($_GET['language']) ? $_GET['language'] : '');
            if (empty($page['errors'])) { redirect($base_url); }
        }

        $default_language = get_default_language();
        $tpl_languages    = [];
        foreach ($languages->fs_languages as $language_id => $language) {
            $language['u_action'] = add_url_params($base_url, ['language' => $language_id]);
            if (in_array($language_id, array_keys($languages->db_languages))) {
                $language['state']      = 'active';
                $language['deactivable'] = true;
                if (count($languages->db_languages) <= 1) { $language['deactivable'] = false; $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, you need at least one language.'); }
                if ($language_id == $default_language) { $language['deactivable'] = false; $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, first set another language as default.'); }
            } else {
                $language['state'] = 'inactive';
            }
            if ($language_id == $default_language) { $language['is_default'] = true; array_unshift($tpl_languages, $language); }
            else { $language['is_default'] = false; $tpl_languages[] = $language; }
        }

        $tpl->assign(['languages' => $tpl_languages]);
        $tpl->append('language_states', 'active');
        $tpl->append('language_states', 'inactive');

        $missing_language_ids = array_diff(array_keys($languages->db_languages), array_keys($languages->fs_languages));
        $langRepo = ServiceLocator::get(LanguageRepository::class);
        foreach ($missing_language_ids as $language_id) { $langRepo->reassignUsers((string) $language_id, get_default_language()); $langRepo->deactivate((string) $language_id); }

        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        $tpl->assign('CONF_ENABLE_EXTENSIONS_INSTALL', Config::enableExtensionsInstall());
        $tpl->assign('page_data_json', json_encode(['str_delete_language_confirm' => l10n('Are you sure you want to delete the language "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }

    // ── languages_new ─────────────────────────────────────────────────────────

    private function languagesNew(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableExtensionsInstall()) { throw new ConfigException('Piwigo extensions install/update system is disabled'); }

        $tpl->set_filenames(['languages' => 'languages_new.tpl']);
        $pageStr  = is_scalar($page['page']) ? (string) $page['page'] : 'languages_new';
        $tabStr   = is_scalar($page['tab'] ?? null) ? (string) $page['tab'] : '';
        $base_url = get_root_url() . 'admin.php?page=' . $pageStr . '&tab=' . $tabStr;

        $languages = new Languages();
        $languages->get_db_languages();

        $languages_dir = PHPWG_ROOT_PATH . 'language';
        if (!is_writable($languages_dir)) { PageState::current()->addError(l10n('Add write access to the "%s" directory', 'language')); }

        if (isset($_GET['revision'])) {
            if (!is_webmaster()) { PageState::current()->addError(l10n('Webmaster status is required.')); }
            else {
                check_pwg_token();
                $install_status = $languages->extract_language_files('install', is_string($_GET['revision']) ? $_GET['revision'] : '');
                redirect($base_url . '&installstatus=' . $install_status);
            }
        }

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':               PageState::current()->addInfo(l10n('Language has been successfully installed')); break;
                case 'temp_path_error':  PageState::current()->addError(l10n('Can\'t create temporary file.')); break;
                case 'dl_archive_error': PageState::current()->addError(l10n('Can\'t download archive.')); break;
                case 'archive_error':    PageState::current()->addError(l10n('Can\'t read or extract archive.')); break;
                default:                 PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars(is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : '')));
            }
        }

        if ($languages->get_server_languages(true)) {
            foreach ($languages->server_languages as $language) {
                $revDate  = is_scalar($language['revision_date'] ?? null) ? (string) $language['revision_date'] : '';
                [$date,]  = explode(' ', $revDate);
                $revId    = is_scalar($language['revision_id'] ?? null) ? (string) $language['revision_id'] : '';
                $extId    = is_scalar($language['extension_id'] ?? null) ? (string) $language['extension_id'] : '';
                $dlUrl    = is_scalar($language['download_url'] ?? null) ? (string) $language['download_url'] : '';
                $url_auto_install = htmlentities($base_url) . '&amp;revision=' . $revId . '&amp;pwg_token=' . get_pwg_token();
                $tpl->append('languages', ['EXT_NAME' => is_scalar($language['extension_name'] ?? null) ? (string) $language['extension_name'] : '', 'EXT_DESC' => is_scalar($language['extension_description'] ?? null) ? (string) $language['extension_description'] : '', 'EXT_URL' => PEM_URL . '/extension_view.php?eid=' . $extId, 'VERSION' => is_scalar($language['revision_name'] ?? null) ? (string) $language['revision_name'] : '', 'VER_DESC' => is_scalar($language['revision_description'] ?? null) ? (string) $language['revision_description'] : '', 'DATE' => $date, 'AUTHOR' => is_scalar($language['author_name'] ?? null) ? (string) $language['author_name'] : '', 'URL_INSTALL' => $url_auto_install, 'URL_DOWNLOAD' => $dlUrl . '&amp;origin=piwigo_download']);
            }
        } else {
            PageState::current()->addError(l10n('Can\'t connect to server.'));
        }

        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }

    // ── updates ───────────────────────────────────────────────────────────────

    private function updates(): void
    {
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableExtensionsInstall() && !Config::enableCoreUpdate()) {
            throw new ConfigException('update system is disabled');
        }

        $my_base_url = get_root_url() . 'admin.php?page=updates';

        if (isset($_GET['tab'])) {
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'pwg';
        } else {
            $page['tab'] = 'pwg';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('updates');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tab = (string) $page['tab'];
        if ($tab === 'pwg') {
            $this->updatesPwg();
        } elseif ($tab === 'ext') {
            $this->updatesExt();
        } else {
            require PHPWG_ROOT_PATH . 'admin/updates_' . $tab . '.php';
        }
    }

    // ── updates_ext ───────────────────────────────────────────────────────────

    private function updatesExt(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        if (!is_webmaster()) {
            PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
        }

        $updates_ignored_raw = Config::raw('updates_ignored');
        Config::override('updates_ignored', safe_unserialize(is_string($updates_ignored_raw) ? $updates_ignored_raw : ''));

        $pageStr = is_scalar($page['page']) ? (string) $page['page'] : 'updates';
        $autoupdate = new Updates($pageStr);

        $show_reset = false;
        if (!$autoupdate->get_server_extensions()) {
            PageState::current()->addError(l10n('Can\'t connect to server.'));
            return;
        }

        $updates_extension = [];

        foreach ($autoupdate->types as $type) {
            $fs         = 'fs_' . $type;
            $server     = 'server_' . $type;
            $server_ext = $autoupdate->$type->$server;
            $fs_ext     = $autoupdate->$type->$fs;

            if (empty($server_ext)) { continue; }
            $updates_extension[$type] = [];

            foreach ($fs_ext as $ext_id => $fs_ext_item) {
                if (!isset($fs_ext_item['extension']) || !isset($server_ext[$fs_ext_item['extension']])) { continue; }
                if ('auto' === $fs_ext_item['version']) { continue; }

                $ext_info = $server_ext[$fs_ext_item['extension']];
                $updates_ignored     = Config::raw('updates_ignored');
                $updates_ignored_arr = is_array($updates_ignored) ? $updates_ignored : [];
                $updates_ignored_for_type = is_array($updates_ignored_arr[$type] ?? null) ? $updates_ignored_arr[$type] : [];

                if (!safe_version_compare($fs_ext_item['version'], $ext_info['revision_name'], '>=')) {
                    array_push($updates_extension[$type], [
                        'ID' => $ext_info['extension_id'], 'REVISION_ID' => $ext_info['revision_id'], 'EXT_ID' => $ext_id, 'EXT_NAME' => $fs_ext_item['name'],
                        'EXT_URL' => PEM_URL . '/extension_view.php?eid=' . $ext_info['extension_id'] . '#changelog',
                        'REV_DESC' => trim((string) $ext_info['revision_description'], " \n\r"),
                        'CURRENT_VERSION' => $fs_ext_item['version'], 'NEW_VERSION' => $ext_info['revision_name'],
                        'URL_DOWNLOAD' => $ext_info['download_url'] . '&amp;origin=piwigo_download',
                        'IGNORED' => in_array($ext_id, $updates_ignored_for_type),
                    ]);
                }
                if (!empty($updates_ignored_for_type)) { $show_reset = true; }
            }
        }

        $ext_type = $pageStr == 'updates' ? 'extensions' : $pageStr;
        $tpl->assign('UPDATES_EXTENSION', $updates_extension);
        $tpl->assign('SHOW_RESET', $show_reset);
        $tpl->assign('PWG_TOKEN', get_pwg_token());
        $tpl->assign('EXT_TYPE', $ext_type);
        $tpl->assign('isWebmaster', is_webmaster() ? 1 : 0);
        $tpl->assign('page_data_json', json_encode(['pwg_token' => get_pwg_token(), 'ext_type' => $ext_type, 'str_error_head' => l10n('ERROR'), 'str_error_msg' => l10n('an error happened'), 'str_restore' => l10n('Reset ignored updates'), 'str_confirm_update_all' => l10n('Are you sure you want to update all extensions?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->set_filename('plugin_admin_content', 'updates_ext.tpl');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Updates'));
    }

    // ── updates_pwg ───────────────────────────────────────────────────────────

    private function updatesPwg(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        if (!Config::enableCoreUpdate()) { throw new ConfigException('Piwigo core update system is disabled'); }

        require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';

        $step = is_numeric($_GET['step'] ?? null) ? (int) $_GET['step'] : 0;
        [$ct_env, $ct_build_version] = get_container_info();

        if ('Official' === $ct_env) {
            $tpl->assign(['CONTAINER_VERSION' => $ct_build_version, 'DOCKER_UPDATE_GUIDE_URL' => PHPWG_URL . '/guide-update-docker']);
            check_input_parameter('to', $_GET, false, '/^\d+\.\d+\.\d+[a-z]?$/');
            $upgrade_to_raw = isset($_GET['to']) ? (is_scalar($_GET['to']) ? (string) $_GET['to'] : '') : '';
            $upgrade_to     = $upgrade_to_raw !== '' ? (string) preg_replace('/[a-z]$/', '', $upgrade_to_raw) : '';
        } else {
            check_input_parameter('to', $_GET, false, '/^\d+\.\d+\.\d+$/');
            $upgrade_to = isset($_GET['to']) ? (is_scalar($_GET['to']) ? (string) $_GET['to'] : '') : '';
        }

        $updates      = new Updates();
        $new_versions = $updates->get_piwigo_new_versions();

        if ($step == 0) {
            if (isset($new_versions['minor']) && isset($new_versions['major']))     { $step = 1; $upgrade_to = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : ''; }
            elseif (isset($new_versions['minor']))                                  { $step = 2; $upgrade_to = is_scalar($new_versions['minor']) ? (string) $new_versions['minor'] : ''; }
            elseif (isset($new_versions['major']))                                  { $step = 3; $upgrade_to = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : ''; }
            $tpl->assign('CHECK_VERSION', $new_versions['piwigo.org-checked']);
            $tpl->assign('DEV_VERSION', $new_versions['is_dev']);
        }

        if ($step == 2 && is_webmaster()) {
            if (isset($_POST['submit']) && isset($_POST['upgrade_to'])) { Updates::upgrade_to(is_scalar($_POST['upgrade_to']) ? (string) $_POST['upgrade_to'] : '', $step); }
        }

        if ($step == 3 && is_webmaster()) {
            if (isset($_POST['submit']) && isset($_POST['upgrade_to'])) { Updates::upgrade_to(is_scalar($_POST['upgrade_to']) ? (string) $_POST['upgrade_to'] : '', $step); }
            $updates->get_merged_extensions($upgrade_to);
            $updates->get_server_extensions($upgrade_to);
            $tpl->assign('missing', $updates->missing);
        }

        if (isset($new_versions['minor_php']) && version_compare(phpversion(), is_scalar($new_versions['minor_php']) ? (string) $new_versions['minor_php'] : '', '<')) { $tpl->assign('MINOR_RELEASE_PHP_REQUIRED', $new_versions['minor_php']); }
        if (isset($new_versions['major_php']) && version_compare(phpversion(), is_scalar($new_versions['major_php']) ? (string) $new_versions['major_php'] : '', '<')) { $tpl->assign('MAJOR_RELEASE_PHP_REQUIRED', $new_versions['major_php']); }

        if (!is_webmaster()) { PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'))); }

        $pageUpdatedVersion = is_scalar($page['updated_version'] ?? null) ? (string) $page['updated_version'] : PHPWG_VERSION;
        $tpl->assign(['STEP' => $step, 'PIWIGO_CURRENT_VERSION' => $pageUpdatedVersion, 'UPGRADE_TO' => $upgrade_to]);

        if (isset($new_versions['minor'])) {
            $minor_ver = is_scalar($new_versions['minor']) ? (string) $new_versions['minor'] : '';
            $tpl->assign(['MINOR_VERSION' => $minor_ver, 'MINOR_RELEASE_URL' => (('Official' === $ct_env) ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $minor_ver) : PHPWG_URL . '/releases/' . $minor_ver)]);
        }

        if (isset($new_versions['major'])) {
            $major_ver = is_scalar($new_versions['major']) ? (string) $new_versions['major'] : '';
            $tpl->assign(['MAJOR_VERSION' => $major_ver, 'MAJOR_RELEASE_URL' => PHPWG_URL . '/releases/' . (('Official' === $ct_env) ? substr($major_ver, 0, -1) : $major_ver), 'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $major_ver), 'MAJOR_VERSION_PWG' => preg_replace('/[a-z]$/', '', $major_ver)]);
        }

        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Updates'));
        $tpl->assign('page_data_json', json_encode(['str_are_you_sure' => l10n('Are you sure?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->set_filename('plugin_admin_content', 'updates_pwg.tpl');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
    }

    // ── extend_for_templates ──────────────────────────────────────────────────

    private function extendForTemplates(): void
    {
        $tpl = TemplateRegistry::current();

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $tpl_extension = safe_unserialize(Config::extentsForTemplates() ?? '');
        $new_extensions = get_extents();

        $relevant_parameters = ['----------', 'category', 'favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats', 'created-monthly-calendar', 'posted-monthly-calendar', 'search', 'flat', 'list', 'tags'];
        $permalinks = array_column(get_dbal_connection()->executeQuery('SELECT permalink FROM ' . CATEGORIES_TABLE . ' WHERE permalink IS NOT NULL')->fetchAllAssociative(), 'permalink');
        $relevant_parameters = array_merge($relevant_parameters, $permalinks);

        $eligible_templates = ['----------' => 'N/A', 'about.tpl' => 'about', 'comments.tpl' => 'comments', 'comment_list.tpl' => 'comment_list', 'footer.tpl' => 'tail', 'header.tpl' => 'header', 'identification.tpl' => 'identification', 'index.tpl' => 'index', 'mainpage_categories.tpl' => 'index_category_thumbnails', 'menubar.tpl' => 'menubar', 'menubar_categories.tpl' => 'mbCategories', 'menubar_identification.tpl' => 'mbIdentification', 'menubar_links.tpl' => 'mbLinks', 'menubar_menu.tpl' => 'mbMenu', 'menubar_specials.tpl' => 'mbSpecials', 'menubar_tags.tpl' => 'mbTags', 'month_calendar.tpl' => 'month_calendar', 'navigation_bar.tpl' => 'navbar', 'nbm.tpl' => 'nbm', 'notification.tpl' => 'notification', 'password.tpl' => 'password', 'picture.tpl' => 'picture', 'picture_content.tpl' => 'default_content', 'picture_nav_buttons.tpl' => 'picture_nav_buttons', 'popuphelp.tpl' => 'popuphelp', 'profile.tpl' => 'profile', 'profile_content.tpl' => 'profile_content', 'redirect.tpl' => 'redirect', 'register.tpl' => 'register', 'search.tpl' => 'search', 'slideshow.tpl' => 'slideshow', 'tags.tpl' => 'tags', 'thumbnails.tpl' => 'index_thumbnails'];

        $flip_templates      = array_flip($eligible_templates);
        $available_templates = array_merge(['N/A' => '----------'], get_dirs(PHPWG_ROOT_PATH . 'themes'));

        if (isset($_POST['submit'])) {
            $reptpl_arr  = is_array($_POST['reptpl'] ?? null) ? $_POST['reptpl'] : [];
            $original_arr = is_array($_POST['original'] ?? null) ? $_POST['original'] : [];
            $url_arr     = is_array($_POST['url'] ?? null) ? $_POST['url'] : [];
            $bound_arr   = is_array($_POST['bound'] ?? null) ? $_POST['bound'] : [];
            $replacements = [];
            $i = 0;
            while (isset($reptpl_arr[$i])) {
                $newtpl     = is_scalar($reptpl_arr[$i]) ? (string) $reptpl_arr[$i] : '';
                $original   = is_scalar($original_arr[$i] ?? null) ? (string) $original_arr[$i] : '';
                $handle     = $eligible_templates[$original] ?? 'N/A';
                $url_keyword = is_scalar($url_arr[$i] ?? null) ? (string) $url_arr[$i] : '';
                if ($url_keyword == '----------') { $url_keyword = 'N/A'; }
                $bound_tpl  = is_scalar($bound_arr[$i] ?? null) ? (string) $bound_arr[$i] : '';
                if ($bound_tpl == '----------') { $bound_tpl = 'N/A'; }
                if ($handle != 'N/A') { $replacements[$newtpl] = [$handle, $url_keyword, $bound_tpl]; }
                $i++;
            }
            Config::override('extents_for_templates', serialize($replacements));
            $tpl_extension = $replacements;
            conf_update_param('extents_for_templates', Config::extentsForTemplates());
            PageState::current()->addInfo(l10n('Templates configuration has been recorded.'));
        }

        foreach ($tpl_extension as $file => $conditions) {
            $fileStr = (string) $file;
            if (!in_array($fileStr, $new_extensions)) { unset($tpl_extension[$file]); }
            else { $new_extensions = array_diff($new_extensions, [$fileStr]); }
        }
        foreach ($new_extensions as $file) { $tpl_extension[$file] = ['N/A', 'N/A', 'N/A']; }

        $tpl->set_filenames(['extend_for_templates' => 'extend_for_templates.tpl']);
        $tpl->assign(['U_HELP' => get_root_url() . 'admin/popuphelp.php?page=extend_for_templates']);

        ksort($tpl_extension);
        foreach ($tpl_extension as $file => $conditions) {
            if (!is_array($conditions)) { continue; }
            $handle      = is_scalar($conditions[0] ?? null) ? (string) $conditions[0] : 'N/A';
            $url_keyword = is_scalar($conditions[1] ?? null) ? (string) $conditions[1] : 'N/A';
            $bound_tpl   = is_scalar($conditions[2] ?? null) ? (string) $conditions[2] : 'N/A';
            $tpl->append('extents', ['replacer' => $file, 'url_parameter' => $relevant_parameters, 'original_tpl' => array_keys($eligible_templates), 'bound_tpl' => $available_templates, 'selected_tpl' => $flip_templates[$handle] ?? '', 'selected_url' => $url_keyword, 'selected_bound' => $bound_tpl]);
        }

        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Extend for templates'));
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'extend_for_templates');
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function cmpThemes(array $a, array $b): int
    {
        $s = ['active' => 0, 'inactive' => 1];
        if (!empty($a['IS_DEFAULT'])) { return -1; }
        if (!empty($b['IS_DEFAULT'])) { return 1; }
        $a_state = is_string($a['STATE'] ?? null) ? $a['STATE'] : '';
        $b_state = is_string($b['STATE'] ?? null) ? $b['STATE'] : '';
        $a_name  = is_scalar($a['NAME'] ?? null) ? (string) $a['NAME'] : '';
        $b_name  = is_scalar($b['NAME'] ?? null) ? (string) $b['NAME'] : '';
        if ($a_state == $b_state) { return strcasecmp($a_name, $b_name); }
        return (($s[$a_state] ?? 0) >= ($s[$b_state] ?? 0) ? 1 : -1);
    }
}
