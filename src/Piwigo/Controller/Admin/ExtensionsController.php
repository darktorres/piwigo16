<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Languages;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Themes;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DateService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Exception\AuthException;
use Piwigo\Exception\ConfigException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\Language\LanguageRepository;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Plugins\LoadedPluginRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;

final readonly class ExtensionsController
{
    /** @var list<string> */
    public const array PAGES = [
        'plugins', 'plugins_installed', 'plugins_new', 'plugin',
        'themes', 'themes_installed', 'themes_new', 'themes_standard_pages', 'theme',
        'languages', 'languages_installed', 'languages_new',
        'updates', 'updates_ext', 'updates_pwg',
        'extend_for_templates',
    ];

    public function __construct(
        private Connection $conn,
        private AdminService $adminService,
        private ConfigService $configService,
        private DateService $dateService,
        private LanguageRepository $languageRepository,
        private PermissionService $permissionService,
        private Plugins $plugins,
        private PluginRepository $pluginRepository,
        private PreferencesService $preferencesService,
        private SessionService $sessionService,
        private StringUtil $stringUtil,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserService $userService,
        private Util $util,
    ) {
    }

    public function handle(string $page): void
    {
        if ($page === 'plugins') {
            $this->plugins();
        } elseif ($page === 'plugins_installed') {
            $this->pluginsInstalled();
        } elseif ($page === 'plugins_new') {
            $this->pluginsNew();
        } elseif ($page === 'plugin') {
            $this->plugin();
        } elseif ($page === 'themes') {
            $this->themes();
        } elseif ($page === 'themes_installed') {
            $this->themesInstalled();
        } elseif ($page === 'themes_new') {
            $this->themesNew();
        } elseif ($page === 'themes_standard_pages') {
            $this->themesStandardPages();
        } elseif ($page === 'theme') {
            $this->theme();
        } elseif ($page === 'languages') {
            $this->languages();
        } elseif ($page === 'languages_installed') {
            $this->languagesInstalled();
        } elseif ($page === 'languages_new') {
            $this->languagesNew();
        } elseif ($page === 'updates') {
            $this->updates();
        } elseif ($page === 'updates_ext') {
            $this->updatesExt();
        } elseif ($page === 'updates_pwg') {
            $this->updatesPwg();
        } elseif ($page === 'extend_for_templates') {
            $this->extendForTemplates();
        }
    }

    // ── plugins ───────────────────────────────────────────────────────────────

    private function plugins(): void
    {
        $tpl = TemplateRegistry::current();

        $my_base_url = $this->urlGenerator->admin('plugins');
        $GLOBALS['my_base_url'] = $my_base_url;

        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'installed';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('plugins');
        $tabsheet->select($tab);
        $tabsheet->assign();
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Plugins'));
        } elseif ($tab === 'installed') {
            $this->pluginsInstalled();
        } elseif ($tab === 'new') {
            $this->pluginsNew();
        }
    }

    // ── plugins_installed ─────────────────────────────────────────────────────

    private function pluginsInstalled(): void
    {
        $tpl = TemplateRegistry::current();

        if (isset($_GET['show_details'])) {
            $show_details = (1 == $_GET['show_details']);
            $this->sessionService->setSessionVar('plugins_show_details', $show_details);
        } else {
            $show_details = $this->sessionService->getSessionVar('plugins_show_details', false);
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'plugins';
        $base_url = $this->urlGenerator->admin($pageStr);
        $pwg_token = $this->util->getPwgToken();
        $action_url = $base_url . '&plugin=' . '%s' . '&pwg_token=' . $pwg_token;

        $plugins = $this->plugins;

        if (isset($_GET['incompatible_plugins'])) {
            $incompatible_plugins_raw = $plugins->getIncompatiblePlugins();
            if (false === $incompatible_plugins_raw) {
                echo json_encode([]);
                exit;
            }
            $incompatible_plugins = [];
            foreach ($incompatible_plugins_raw as $plugin => $version) {
                if ($plugin == '~~expire~~') {
                    continue;
                }
                $incompatible_plugins[] = $plugin;
            }
            echo json_encode($incompatible_plugins);
            exit;
        }

        $plugin_menu_links_deprec = EventDispatcher::dispatch('get_admin_plugin_menu_links', []);
        $settings_url_for_plugin_deprec = [];
        foreach ($plugin_menu_links_deprec as $value) {
            if (!is_array($value)) {
                continue;
            }
            $vUrl = is_scalar($value['URL'] ?? null) ? (string) $value['URL'] : '';
            if (preg_match('/^admin\.php\?page=plugin-(.*)$/', $vUrl, $matches)) {
                $settings_url_for_plugin_deprec[$matches[1]] = $vUrl;
            } elseif (preg_match('/^.*section=(.*?)[\/&%].*$/', $vUrl, $matches)) {
                $settings_url_for_plugin_deprec[$matches[1]] = $vUrl;
            }
        }

        $plugins->sortFsPlugins('name');
        $merged_extensions    = $plugins->getMergedExtensions();
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
                $setting_url = $this->urlGenerator->admin('plugin-' . $plugin_id);
                if (preg_match('/^piwigo-(videojs|openstreetmap)$/', $plugin_id)) {
                    $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
                }
            }

            $fsPluginUri = $fs_plugin['uri'] ?? null;
            $visit_url = str_replace(['http://piwigo.org/ext', 'https://piwigo.org/ext'], PEM_URL, is_scalar($fsPluginUri) ? (string) $fsPluginUri : '');

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
                $this->pluginRepository->updateState($plugin_id, 'inactive');
                $tpl_plugin['STATE'] = 'merged';
                $tpl_plugin['DESC']  = Lang::t('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
                $merged_plugins      = true;
            }

            $pluginState = is_string($tpl_plugin['STATE'] ?? null) ? $tpl_plugin['STATE'] : 'inactive';
            if (isset($count_types_plugins[$pluginState])) {
                $count_types_plugins[$pluginState]++;
            }
            $tpl_plugins[] = $tpl_plugin;
        }

        $tpl->append('plugin_states', 'active');
        $tpl->append('plugin_states', 'inactive');
        if ($merged_plugins) {
            $tpl->append('plugin_states', 'merged');
        }

        $missing_plugin_ids = array_diff(array_keys($plugins->db_plugins_by_id), array_keys($plugins->fs_plugins));
        if (count($missing_plugin_ids) > 0) {
            foreach ($missing_plugin_ids as $plugin_id) {
                $tpl_plugins[] = ['NAME' => $plugin_id, 'ID' => $plugin_id, 'VERSION' => $plugins->db_plugins_by_id[$plugin_id]['version'], 'DESC' => Lang::t('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'), 'U_ACTION' => sprintf($action_url, $plugin_id), 'STATE' => 'missing'];
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
            'isWebmaster'          => $this->permissionService->isWebmaster() ? 1 : 0,
            'ADMIN_PAGE_TITLE'     => Lang::t('Plugins'),
            'view_selector'        => $this->preferencesService->userprefsGetParam('plugin-manager-view', 'classic'),
            'CONF_ENABLE_EXTENSIONS_INSTALL' => Config::enableExtensionsInstall(),
            'page_data_json'       => json_encode([
                'pwg_token'          => $pwg_token,
                'isWebmaster'        => $this->permissionService->isWebmaster() ? 1 : 0,
                'show_details'       => $show_details,
                'nb_plugin'          => ['all' => $count_types_plugins['active'] + $count_types_plugins['inactive'] + $count_types_plugins['missing'] + $count_types_plugins['merged'], 'active' => $count_types_plugins['active'], 'inactive' => $count_types_plugins['inactive'], 'other' => $count_types_plugins['missing'] + $count_types_plugins['merged']],
                'activate_msg'       => Lang::t('Do you want to activate anyway?'),
                'cancel_msg'         => Lang::t('No, I have changed my mind'),
                'confirm_msg'        => Lang::t('Yes, I am sure'),
                'deactivate_all_msg' => Lang::t('Deactivate all'),
                'delete_plugin_msg'  => Lang::t('Are you sure you want to delete the plugin "%s"?'),
                'deleted_plugin_msg' => Lang::t('Plugin "%s" deleted!'),
                'incompatible_msg'   => Lang::t('WARNING! This plugin does not seem to be compatible with this version of Piwigo.'),
                'not_webmaster'      => Lang::t('Webmaster status required'),
                'nothing_found'      => Lang::t('No plugins found'),
                'plugin_action_error' => Lang::t('an error happened'),
                'plugin_added_str'   => Lang::t('Activated'),
                'plugin_deactivated_str' => Lang::t('Deactivated'),
                'plugin_found'       => Lang::t('%s plugin found'),
                'plugin_restored_str' => Lang::t('Restored'),
                'restore_plugin_msg' => Lang::t('Are you sure you want to restore the plugin "%s"?'),
                'str_restore_def'    => Lang::t('While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'),
                'uninstall_plugin_msg' => Lang::t('Are you sure you want to uninstall the plugin "%s"?'),
                'x_plugins_found'    => Lang::t('%s plugins found'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'plugins_installed.latte');
    }

    // ── plugins_new ───────────────────────────────────────────────────────────

    private function pluginsNew(): void
    {
        $tpl = TemplateRegistry::current();

        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'plugins_new';
        $tabStr   = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
        $base_url = $this->urlGenerator->admin($pageStr) . '&tab=' . $tabStr;

        $plugins = $this->plugins;

        if (isset($_GET['revision']) && isset($_GET['extension'])) {
            if (!$this->permissionService->isWebmaster()) {
                PageState::current()->addError(Lang::t('Webmaster status is required.'));
            } else {
                $this->util->checkPwgToken();
                $install_status = $plugins->extractPluginFiles('install', is_string($_GET['revision']) ? $_GET['revision'] : '', is_string($_GET['extension']) ? $_GET['extension'] : '', $plugin_id);
                $this->util->redirect($base_url . '&installstatus=' . $install_status . '&plugin_id=' . ($plugin_id ?? ''));
            }
        }

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    $activate_url = $this->urlGenerator->admin('plugins') . '&filter=deactivated';
                    PageState::current()->addInfo(Lang::t('Plugin has been successfully copied'));
                    PageState::current()->addInfo(new Html('<a href="' . $activate_url . '">' . Lang::t('Activate it now') . '</a>'));
                    $pluginIdRaw = $_GET['plugin_id'] ?? null;
                    $getPluginId = is_string($pluginIdRaw) ? $pluginIdRaw : '';
                    if ($getPluginId !== '' && isset($plugins->fs_plugins[$getPluginId])) {
                        $this->util->pwgActivity('system', ActivitySystem::Plugin, 'install', ['plugin_id' => $getPluginId, 'version' => $plugins->fs_plugins[$getPluginId]['version']]);
                    }
                    break;
                case 'temp_path_error':   PageState::current()->addError(Lang::t('Can\'t create temporary file.'));
                    break;
                case 'dl_archive_error':  PageState::current()->addError(Lang::t('Can\'t download archive.'));
                    break;
                case 'archive_error':     PageState::current()->addError(Lang::t('Can\'t read or extract archive.'));
                    break;
                default:                  PageState::current()->addError(Lang::t('An error occured during extraction (%s).', htmlspecialchars(is_string($rawInstallStatus = $_GET['installstatus']) ? $rawInstallStatus : '')));
                    PageState::current()->addError(Lang::t('Please check "plugins" folder and sub-folders permissions (CHMOD).'));
            }
        }

        $tpl->assign('order_options', ['date' => Lang::t('Post date'), 'revision' => Lang::t('Last revisions'), 'name' => Lang::t('Name'), 'author' => Lang::t('Author'), 'downloads' => Lang::t('Number of downloads')]);

        if ($plugins->getServerPlugins(true)) {
            if ($this->sessionService->getSessionVar('plugins_new_order') != null) {
                $order_selected = $this->sessionService->getSessionVar('plugins_new_order');
                $plugins->sortServerPlugins(is_string($order_selected) ? $order_selected : 'date');
                $tpl->assign('order_selected', is_scalar($order_selected) ? (string) $order_selected : '');
            } else {
                $plugins->sortServerPlugins('date');
                $tpl->assign('order_selected', 'date');
            }

            foreach ($plugins->server_plugins as $plugin) {
                $ext_desc  = trim(is_scalar($plugin['extension_description'] ?? null) ? (string) $plugin['extension_description'] : '', " \n\r");
                [$small_desc] = explode("\n", wordwrap($ext_desc, 200));
                $revisionId    = is_scalar($plugin['revision_id'] ?? null) ? (string) $plugin['revision_id'] : '';
                $extensionId   = is_scalar($plugin['extension_id'] ?? null) ? (string) $plugin['extension_id'] : '';
                $url_auto_install = htmlentities($base_url) . '&revision=' . $revisionId . '&extension=' . $extensionId . '&pwg_token=' . $this->util->getPwgToken();

                $revisionDateRaw    = $plugin['revision_date'] ?? null;
                $rev_date           = date_create(is_string($revisionDateRaw) ? $revisionDateRaw : '');
                $now_date           = date_create();
                $last_revision_diff = ($rev_date !== false && $now_date !== false) ? date_diff($rev_date, $now_date) : new \DateInterval('P0D');

                $certification = 1;
                if ($last_revision_diff->days < 90) {
                    $certification = 3;
                } elseif ($last_revision_diff->days < 180) {
                    $certification = 2;
                } elseif ($last_revision_diff->y > 3) {
                    $certification = 0;
                }

                $revDateStr = is_string($revisionDateRaw) ? $revisionDateRaw : null;
                $tpl->append('plugins', [
                    'ID'                       => $extensionId,
                    'EXT_NAME'                 => is_scalar($plugin['extension_name'] ?? null) ? $plugin['extension_name'] : '',
                    'EXT_URL'                  => PEM_URL . '/extension_view.php?eid=' . $extensionId,
                    'SMALL_DESC'               => trim($small_desc, " \r\n"),
                    'BIG_DESC'                 => $ext_desc,
                    'VERSION'                  => is_scalar($plugin['revision_name'] ?? null) ? $plugin['revision_name'] : '',
                    'REVISION_DATE'            => preg_replace('/[^0-9]/', '', (string) strtotime($revDateStr ?? '')),
                    'REVISION_FORMATED_DATE'   => $this->dateService->formatDate($revDateStr, ['day', 'month', 'year']) . ', ' . $this->dateService->timeSince($revDateStr, 'day'),
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
            PageState::current()->addError(Lang::t('Can\'t connect to server.'));
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Plugins'));
        $tpl->assign('page_data_json', json_encode([
            'str_confirm_msg'   => Lang::t('Yes, I am sure'), 'str_cancel_msg' => Lang::t('No, I have changed my mind'), 'str_install_title' => Lang::t('Are you sure you want to install the plugin "%s"?'),
            'str_x_month' => Lang::t('%d month'), 'str_x_months' => Lang::t('%d months'), 'str_x_year' => Lang::t('%d year'), 'str_x_years' => Lang::t('%d years'),
            'str_from_begining' => Lang::t('since the beginning'),
            'strs_certification' => ['0' => Lang::t('This plugin have no update since 3 years ! It may be outdated'), '1' => Lang::t('This plugin has no recent update'), '2' => Lang::t('This plugin was updated less than 6 months ago'), '3' => Lang::t('This plugin have been updated recently')],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'plugins_new.latte');
    }

    // ── plugin ────────────────────────────────────────────────────────────────

    private function plugin(): void
    {
        $raw_section = $_GET['section'] ?? '';
        $sections = explode('/', is_string($raw_section) ? $raw_section : '');
        $sections = array_values(array_filter($sections, fn (string $s): bool => $s !== ''));
        foreach ($sections as $sectionToken) {
            if ($sectionToken == '..' || !preg_match('/^[a-zA-Z0-9_\.-]+$/', $sectionToken)) {
                throw new ValidationException('invalid section token [' . htmlentities($sectionToken) . ']');
            }
        }

        if (count($sections) < 2) {
            throw new ValidationException('Invalid plugin URL');
        }
        $plugin_id = $sections[0];
        if (!preg_match('/^[\w-]+$/', $plugin_id)) {
            throw new ValidationException('Invalid plugin identifier');
        }

        if (!LoadedPluginRegistry::isLoaded($plugin_id)) {
            throw new AuthException('Invalid URL - plugin ' . $plugin_id . ' not active');
        }

        $filename = Config::pluginsPath() . implode('/', $sections);
        if (is_file($filename)) {
            require_once $filename;
        } else {
            throw new NotFoundException('Missing file ' . htmlentities($filename));
        }
    }

    // ── themes ────────────────────────────────────────────────────────────────

    private function themes(): void
    {
        $tpl = TemplateRegistry::current();

        $my_base_url = $this->urlGenerator->admin('themes');
        $GLOBALS['my_base_url'] = $my_base_url;

        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'installed';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('themes');
        $tabsheet->select($tab);
        $tabsheet->assign();
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));
        } elseif ($tab === 'installed') {
            $this->themesInstalled();
        } elseif ($tab === 'new') {
            $this->themesNew();
        } elseif ($tab === 'standard_pages') {
            $this->themesStandardPages();
        }
    }

    // ── themes_installed ──────────────────────────────────────────────────────

    private function themesInstalled(): void
    {
        $tpl = TemplateRegistry::current();

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'themes';
        $base_url = $this->urlGenerator->admin($pageStr);
        $themes   = Kernel::service(Themes::class);

        if (isset($_GET['action']) && isset($_GET['theme'])) {
            $get_action = is_string($_GET['action']) ? $_GET['action'] : '';
            $get_theme  = is_string($_GET['theme']) ? $_GET['theme'] : '';
            PageState::current()->errors = array_values(array_filter($themes->performAction($get_action, $get_theme), is_string(...)));
            if (empty(PageState::current()->errors)) {
                if ($_GET['action'] == 'activate' || $_GET['action'] == 'deactivate') {
                    $tpl->deleteCompiledTemplates();
                }
                $this->util->redirect($base_url);
            }
        }

        $themes->sortFsThemes();
        $default_theme = $this->userService->getDefaultTheme();
        $db_themes     = $themes->getDbThemes();
        $db_theme_ids  = [];
        foreach ($db_themes as $db_theme) {
            $db_theme_ids[] = $db_theme['id'];
        }

        $tpl_themes = [];
        foreach ($themes->fs_themes as $theme_id => $fs_theme) {
            if ($theme_id == '_base' || $theme_id == 'standard_pages') {
                continue;
            }
            $tpl_theme = ['ID' => $theme_id, 'NAME' => $fs_theme['name'], 'VISIT_URL' => $fs_theme['uri'], 'VERSION' => $fs_theme['version'], 'DESC' => $fs_theme['description'], 'AUTHOR' => $fs_theme['author'], 'AUTHOR_URL' => $fs_theme['author uri'] ?? null, 'PARENT' => $fs_theme['parent'] ?? null, 'SCREENSHOT' => $fs_theme['screenshot'], 'IS_MOBILE' => $fs_theme['mobile'], 'ADMIN_URI' => $fs_theme['admin_uri'] ?? null];

            if (in_array($theme_id, $db_theme_ids)) {
                $tpl_theme['STATE']      = 'active';
                $tpl_theme['IS_DEFAULT'] = ($theme_id == $default_theme);
                $tpl_theme['DEACTIVABLE'] = true;
                if (count($db_theme_ids) <= 1) {
                    $tpl_theme['DEACTIVABLE'] = false;
                    $tpl_theme['DEACTIVATE_TOOLTIP'] = Lang::t('Impossible to deactivate this theme, you need at least one theme.');
                }
                if ($tpl_theme['IS_DEFAULT']) {
                    $tpl_theme['DEACTIVABLE'] = false;
                    $tpl_theme['DEACTIVATE_TOOLTIP'] = Lang::t('Impossible to deactivate the default theme.');
                }
            } else {
                $tpl_theme['STATE'] = 'inactive';
                if (isset($fs_theme['activable']) && !$fs_theme['activable']) {
                    $tpl_theme['ACTIVABLE'] = false;
                    $tpl_theme['ACTIVABLE_TOOLTIP'] = Lang::t('This theme was not designed to be directly activated');
                } else {
                    $tpl_theme['ACTIVABLE'] = true;
                }
                $missing_parent = $themes->missingParentTheme($theme_id);
                if (isset($missing_parent)) {
                    $tpl_theme['ACTIVABLE'] = false;
                    $tpl_theme['ACTIVABLE_TOOLTIP'] = Lang::t('Impossible to activate this theme, the parent theme is missing: %s', $missing_parent);
                }
                $children = $themes->getChildrenThemes($theme_id);
                $tpl_theme['DELETABLE'] = true;
                if (count($children) > 0) {
                    $tpl_theme['DELETABLE'] = false;
                    $tpl_theme['DELETE_TOOLTIP'] = Lang::t('Impossible to delete this theme. Other themes depends on it: %s', implode(', ', $children));
                }
            }
            $tpl_themes[] = $tpl_theme;
        }

        usort($tpl_themes, $this->cmpThemes(...));

        $tpl->assign([
            'activate_baseurl'   => $base_url . '&action=activate&theme=',
            'deactivate_baseurl' => $base_url . '&action=deactivate&theme=',
            'set_default_baseurl' => $base_url . '&action=set_default&theme=',
            'delete_baseurl'     => $base_url . '&action=delete&theme=',
            'tpl_themes'         => $tpl_themes,
        ]);

        EventDispatcher::notify('loc_end_themes_installed');
        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));
        $tpl->assign('CONF_ENABLE_EXTENSIONS_INSTALL', Config::enableExtensionsInstall());
        $tpl->assign('page_data_json', json_encode(['str_delete_theme_confirm' => Lang::t('Are you sure you want to delete the theme "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'themes_installed.latte');
    }

    // ── themes_new ────────────────────────────────────────────────────────────

    private function themesNew(): void
    {
        $tpl = TemplateRegistry::current();

        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'themes_new';
        $tabStr   = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
        $base_url = $this->urlGenerator->admin($pageStr) . '&tab=' . $tabStr;
        $themes   = Kernel::service(Themes::class);

        $themes_dir = PHPWG_ROOT_PATH . 'themes';
        if (!is_writable($themes_dir)) {
            PageState::current()->addError(Lang::t('Add write access to the "%s" directory', 'themes'));
        }

        if (isset($_GET['revision']) && isset($_GET['extension'])) {
            if (!$this->permissionService->isWebmaster()) {
                PageState::current()->addError(Lang::t('Webmaster status is required.'));
            } else {
                $this->util->checkPwgToken();
                $install_status = $themes->extractThemeFiles('install', is_string($_GET['revision']) ? $_GET['revision'] : '', is_string($_GET['extension']) ? $_GET['extension'] : '', $theme_id);
                $this->util->redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . ($theme_id ?? ''));
            }
        }

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    PageState::current()->addInfo(Lang::t('Theme has been successfully installed'));
                    $themeIdRaw = $_GET['theme_id'] ?? null;
                    $theme_id_str = is_string($themeIdRaw) ? $themeIdRaw : '';
                    if ($theme_id_str !== '' && isset($themes->fs_themes[$theme_id_str])) {
                        $this->util->pwgActivity('system', ActivitySystem::Theme, 'install', ['theme_id' => $theme_id_str, 'version' => $themes->fs_themes[$theme_id_str]['version']]);
                    }
                    break;
                case 'temp_path_error':  PageState::current()->addError(Lang::t('Can\'t create temporary file.'));
                    break;
                case 'dl_archive_error': PageState::current()->addError(Lang::t('Can\'t download archive.'));
                    break;
                case 'archive_error':    PageState::current()->addError(Lang::t('Can\'t read or extract archive.'));
                    break;
                default:                 PageState::current()->addError(Lang::t('An error occured during extraction (%s).', htmlspecialchars(is_string($rawInstallStatus = $_GET['installstatus']) ? $rawInstallStatus : '')));
            }
        }

        if ($themes->getServerThemes(true)) {
            foreach ($themes->server_themes as $theme) {
                $theme_revision_id  = is_scalar($theme['revision_id'] ?? null) ? (string) $theme['revision_id'] : '';
                $theme_extension_id = is_scalar($theme['extension_id'] ?? null) ? (string) $theme['extension_id'] : '';
                $url_auto_install   = htmlentities($base_url) . '&revision=' . $theme_revision_id . '&extension=' . $theme_extension_id . '&pwg_token=' . $this->util->getPwgToken();
                $tpl->append('new_themes', ['name' => $theme['extension_name'], 'thumbnail' => key_exists('thumbnail_src', $theme) ? $theme['thumbnail_src'] : '', 'screenshot' => key_exists('screenshot_url', $theme) ? $theme['screenshot_url'] : '', 'install_url' => $url_auto_install]);
            }
        } else {
            PageState::current()->addError(Lang::t('Can\'t connect to server.'));
        }

        $adminTheme = $this->preferencesService->userprefsGetParam('admin_theme', 'dark');
        $tpl->assign('default_screenshot', UrlService::getRootUrl() . 'themes/admin/' . (is_scalar($adminTheme) ? (string) $adminTheme : 'dark') . '/images/missing_screenshot.png');
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'themes_new.latte');
    }

    // ── themes_standard_pages ─────────────────────────────────────────────────

    private function themesStandardPages(): void
    {
        $tpl = TemplateRegistry::current();

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $std_pgs_logo_options = ['piwigo_logo', 'custom_logo', 'gallery_title', 'none'];
        $std_pgs_skin_options = ['default', 'cadmium', 'cobalt', 'fuchsia', 'green', 'lime', 'purple', 'red', 'sienna', 'silver', 'teal'];

        if (isset($_POST['submit']) && $this->permissionService->isWebmaster()) {
            $this->util->checkPwgToken();
            $this->configService->confUpdateParam('use_standard_pages', isset($_POST['use_standard_pages']) && $_POST['use_standard_pages'] !== '', true);
            if (isset($_POST['std_pgs_display_logo']) && in_array($_POST['std_pgs_display_logo'], $std_pgs_logo_options)) {
                $stdPgsDisplayLogoRaw = $_POST['std_pgs_display_logo'];
                $this->configService->confUpdateParam('standard_pages_selected_logo', is_string($stdPgsDisplayLogoRaw) ? $stdPgsDisplayLogoRaw : '', true);
            }
            if (isset($_POST['std_pgs_selected_skin']) && in_array($_POST['std_pgs_selected_skin'], $std_pgs_skin_options)) {
                $stdPgsSelectedSkinRaw = $_POST['std_pgs_selected_skin'];
                $this->configService->confUpdateParam('standard_pages_selected_skin', is_string($stdPgsSelectedSkinRaw) ? $stdPgsSelectedSkinRaw : '', true);
            }
        }

        $stdPgsLogoRaw = $_FILES['std_pgs_logo'] ?? null;
        $std_pgs_logo_file = is_array($stdPgsLogoRaw) ? $stdPgsLogoRaw : [];
        $stdPgsLogoTmpRaw = $std_pgs_logo_file['tmp_name'] ?? null;
        $std_pgs_logo_tmp  = is_string($stdPgsLogoTmpRaw) ? $stdPgsLogoTmpRaw : '';
        if (isset($_FILES['std_pgs_logo']) && !empty($std_pgs_logo_tmp)) {
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = $finfo !== false ? finfo_file($finfo, $std_pgs_logo_tmp) : false;
            $allowed_mimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/svg' => 'svg', 'image/webp' => 'webp'];

            if ($mime_type === false || !in_array($mime_type, array_keys($allowed_mimes))) {
                $tpl->assign(['save_error' => 'Invalid image file.']);
            } else {
                $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'logo';
                if (Util::mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
                    $stdPgsLogoNameRaw = $std_pgs_logo_file['name'] ?? null;
                    $std_pgs_logo_name = is_string($stdPgsLogoNameRaw) ? $stdPgsLogoNameRaw : '';
                    $pathinfo  = pathinfo($std_pgs_logo_name);
                    $file_path = $upload_dir . '/' . $this->stringUtil->str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];
                    $this->configService->confUpdateParam('standard_pages_selected_logo_path', $file_path, true);
                    $logoStream = fopen($std_pgs_logo_tmp, 'rb');
                    if ($logoStream !== false) {
                        StorageRegistry::disk('local')->writeStream('logo/' . basename($file_path), $logoStream);
                        fclose($logoStream);
                    } else {
                        $tpl->assign(['save_error' => "$file_path " . Lang::t('no write access')]);
                    }
                } else {
                    $tpl->assign(['save_error' => sprintf(Lang::t('Add write access to the "%s" directory'), $upload_dir)]);
                }
            }
        }

        $themes = Kernel::service(Themes::class);
        $themes->getFsThemes();
        $is_standard_pages_used = false;
        $standard_pages_used_by = [];
        foreach ($themes->fs_themes as $theme) {
            if (isset($theme['use_standard_pages']) && $theme['use_standard_pages']) {
                $is_standard_pages_used = true;
                array_push($standard_pages_used_by, $theme['name']);
            }
        }

        $tpl->assign([
            'use_standard_pages'            => $this->configService->confGetParam('use_standard_pages', true),
            'std_pgs_selected_logo'         => $this->configService->confGetParam('standard_pages_selected_logo', 'piwigo_logo'),
            'std_pgs_logo_options'          => $std_pgs_logo_options,
            'std_pgs_selected_skin'         => $this->configService->confGetParam('standard_pages_selected_skin', 'default'),
            'std_pgs_skin_options'          => $std_pgs_skin_options,
            'is_standard_pages_used'        => $is_standard_pages_used,
            'standard_pages_used_by'        => $standard_pages_used_by,
            'std_pgs_selected_logo_path'    => $this->configService->confGetParam('standard_pages_selected_logo_path', null),
            'PWG_TOKEN'                     => $this->util->getPwgToken(),
        ]);
        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'themes_standard_pages.latte');
    }

    // ── theme ─────────────────────────────────────────────────────────────────

    private function theme(): void
    {
        $themeParam = $_GET['theme'] ?? null;
        if (!isset($themeParam) || $themeParam === '' || $themeParam === []) {
            throw new ValidationException('Invalid theme URL');
        }

        $themes = Kernel::service(Themes::class);
        if (!in_array($themeParam, array_keys($themes->fs_themes))) {
            throw new ValidationException('Invalid theme');
        }

        $theme_name = is_string($themeParam) ? $themeParam : '';
        $filename   = Config::themesPath() . $theme_name . '/admin/admin.inc.php';
        if (is_file($filename)) {
            require_once $filename;
        } else {
            throw new NotFoundException('Missing file ' . $filename);
        }
    }

    // ── languages ─────────────────────────────────────────────────────────────

    private function languages(): void
    {
        $tpl = TemplateRegistry::current();

        $my_base_url = $this->urlGenerator->admin('languages');
        $GLOBALS['my_base_url'] = $my_base_url;

        if (isset($_GET['tab'])) {
            $this->util->checkInputParameter('tab', $_GET, false, '/^(installed|update|new)$/');
        }
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'installed';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('languages');
        $tabsheet->select($tab);
        $tabsheet->assign();
        if ($tab === 'update') {
            $this->updatesExt();
            $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Languages'));
        } elseif ($tab === 'installed') {
            $this->languagesInstalled();
        } elseif ($tab === 'new') {
            $this->languagesNew();
        }
    }

    // ── languages_installed ───────────────────────────────────────────────────

    private function languagesInstalled(): void
    {
        $tpl = TemplateRegistry::current();

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'languages';
        $base_url = $this->urlGenerator->admin($pageStr);

        $languages = Kernel::service(Languages::class);
        $languages->getDbLanguages();

        $this->util->checkInputParameter('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
        $this->util->checkInputParameter('language', $_GET, false, '/^(' . join('|', array_keys($languages->fs_languages)) . ')$/');

        if (isset($_GET['action']) && isset($_GET['language']) && $this->permissionService->isWebmaster()) {
            PageState::current()->errors = array_values(array_filter($languages->performAction(is_string($_GET['action']) ? $_GET['action'] : '', is_string($_GET['language']) ? $_GET['language'] : ''), is_string(...)));
            if (empty(PageState::current()->errors)) {
                $this->util->redirect($base_url);
            }
        }

        $default_language = $this->userService->getDefaultLanguage();
        $tpl_languages    = [];
        foreach ($languages->fs_languages as $language_id => $language) {
            $language['u_action'] = $this->urlService->addUrlParams($base_url, ['language' => $language_id]);
            if (in_array($language_id, array_keys($languages->db_languages))) {
                $language['state']      = 'active';
                $language['deactivable'] = true;
                if (count($languages->db_languages) <= 1) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = Lang::t('Impossible to deactivate this language, you need at least one language.');
                }
                if ($language_id == $default_language) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = Lang::t('Impossible to deactivate this language, first set another language as default.');
                }
            } else {
                $language['state'] = 'inactive';
            }
            if ($language_id == $default_language) {
                $language['is_default'] = true;
                array_unshift($tpl_languages, $language);
            } else {
                $language['is_default'] = false;
                $tpl_languages[] = $language;
            }
        }

        $tpl->assign(['languages' => $tpl_languages]);
        $tpl->append('language_states', 'active');
        $tpl->append('language_states', 'inactive');

        $missing_language_ids = array_diff(array_keys($languages->db_languages), array_keys($languages->fs_languages));
        $langRepo = $this->languageRepository;
        foreach ($missing_language_ids as $language_id) {
            $langRepo->reassignUsers($language_id, $this->userService->getDefaultLanguage());
            $langRepo->deactivate($language_id);
        }

        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Languages'));
        $tpl->assign('CONF_ENABLE_EXTENSIONS_INSTALL', Config::enableExtensionsInstall());
        $tpl->assign('page_data_json', json_encode(['str_delete_language_confirm' => Lang::t('Are you sure you want to delete the language "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'languages_installed.latte');
    }

    // ── languages_new ─────────────────────────────────────────────────────────

    private function languagesNew(): void
    {
        $tpl = TemplateRegistry::current();
        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        $pageStr  = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'languages_new';
        $tabStr   = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
        $base_url = $this->urlGenerator->admin($pageStr) . '&tab=' . $tabStr;

        $languages = Kernel::service(Languages::class);
        $languages->getDbLanguages();

        $languages_dir = PHPWG_ROOT_PATH . 'language';
        if (!is_writable($languages_dir)) {
            PageState::current()->addError(Lang::t('Add write access to the "%s" directory', 'language'));
        }

        if (isset($_GET['revision'])) {
            if (!$this->permissionService->isWebmaster()) {
                PageState::current()->addError(Lang::t('Webmaster status is required.'));
            } else {
                $this->util->checkPwgToken();
                $install_status = $languages->extractLanguageFiles('install', is_string($_GET['revision']) ? $_GET['revision'] : '');
                $this->util->redirect($base_url . '&installstatus=' . $install_status);
            }
        }

        if (isset($_GET['installstatus'])) {
            match ($_GET['installstatus']) {
                'ok' => PageState::current()->addInfo(Lang::t('Language has been successfully installed')),
                'temp_path_error' => PageState::current()->addError(Lang::t('Can\'t create temporary file.')),
                'dl_archive_error' => PageState::current()->addError(Lang::t('Can\'t download archive.')),
                'archive_error' => PageState::current()->addError(Lang::t('Can\'t read or extract archive.')),
                default => PageState::current()->addError(Lang::t('An error occured during extraction (%s).', htmlspecialchars(is_string($rawInstallStatus = $_GET['installstatus']) ? $rawInstallStatus : ''))),
            };
        }

        if ($languages->getServerLanguages(true)) {
            foreach ($languages->server_languages as $language) {
                $revDate  = is_scalar($language['revision_date'] ?? null) ? (string) $language['revision_date'] : '';
                [$date,]  = explode(' ', $revDate);
                $revId    = is_scalar($language['revision_id'] ?? null) ? (string) $language['revision_id'] : '';
                $extId    = is_scalar($language['extension_id'] ?? null) ? (string) $language['extension_id'] : '';
                $dlUrl    = is_scalar($language['download_url'] ?? null) ? (string) $language['download_url'] : '';
                $url_auto_install = htmlentities($base_url) . '&revision=' . $revId . '&pwg_token=' . $this->util->getPwgToken();
                $tpl->append('languages', ['EXT_NAME' => is_scalar($language['extension_name'] ?? null) ? (string) $language['extension_name'] : '', 'EXT_DESC' => is_scalar($language['extension_description'] ?? null) ? (string) $language['extension_description'] : '', 'EXT_URL' => PEM_URL . '/extension_view.php?eid=' . $extId, 'VERSION' => is_scalar($language['revision_name'] ?? null) ? (string) $language['revision_name'] : '', 'VER_DESC' => is_scalar($language['revision_description'] ?? null) ? (string) $language['revision_description'] : '', 'DATE' => $date, 'AUTHOR' => is_scalar($language['author_name'] ?? null) ? (string) $language['author_name'] : '', 'URL_INSTALL' => $url_auto_install, 'URL_DOWNLOAD' => $dlUrl . '&origin=piwigo_download']);
            }
        } else {
            PageState::current()->addError(Lang::t('Can\'t connect to server.'));
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Languages'));
        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'languages_new.latte');
    }

    // ── updates ───────────────────────────────────────────────────────────────

    private function updates(): void
    {
        if (!Config::enableExtensionsInstall() && !Config::enableCoreUpdate()) {
            throw new ConfigException('update system is disabled');
        }

        $GLOBALS['my_base_url'] = $my_base_url = $this->urlGenerator->admin('updates');

        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'pwg';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('updates');
        $tabsheet->select($tab);
        $tabsheet->assign();
        if ($tab === 'pwg') {
            $this->updatesPwg();
        } elseif ($tab === 'ext') {
            $this->updatesExt();
        }
    }

    // ── updates_ext ───────────────────────────────────────────────────────────

    private function updatesExt(): void
    {
        $tpl = TemplateRegistry::current();

        if (!Config::enableExtensionsInstall()) {
            throw new ConfigException('Piwigo extensions install/update system is disabled');
        }

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $updates_ignored_raw = Config::raw('updates_ignored');
        Config::override('updates_ignored', StringUtil::safeUnserialize(is_string($updates_ignored_raw) ? $updates_ignored_raw : ''));

        $pageStr = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'updates';
        $autoupdate = Kernel::service(Updates::class)->setPage($pageStr);

        $show_reset = false;
        if (!$autoupdate->getServerExtensions()) {
            PageState::current()->addError(Lang::t('Can\'t connect to server.'));
            return;
        }

        $updates_extension = [];

        foreach ($autoupdate->types as $type) {
            $server_ext = $autoupdate->getServerByType($type);
            $fs_ext     = $autoupdate->getFsByType($type);

            if (empty($server_ext)) {
                continue;
            }
            $updates_extension[$type] = [];

            foreach ($fs_ext as $ext_id => $fs_ext_item) {
                $extKey = is_string($fs_ext_item['extension'] ?? null) ? $fs_ext_item['extension'] : null;
                if ($extKey === null || !isset($server_ext[$extKey])) {
                    continue;
                }
                if ('auto' === ($fs_ext_item['version'] ?? '')) {
                    continue;
                }

                $ext_info = $server_ext[$extKey];
                $updates_ignored     = Config::raw('updates_ignored');
                $updates_ignored_arr = is_array($updates_ignored) ? $updates_ignored : [];
                $updates_ignored_for_type = is_array($updates_ignored_arr[$type] ?? null) ? $updates_ignored_arr[$type] : [];

                $extVersion = is_scalar($fs_ext_item['version'] ?? null) ? (string) $fs_ext_item['version'] : '';
                $revName = is_scalar($ext_info['revision_name'] ?? null) ? (string) $ext_info['revision_name'] : '';
                if ($this->stringUtil->safeVersionCompare($extVersion, $revName, '<') === true) {
                    $extId = is_scalar($ext_info['extension_id'] ?? null) ? $ext_info['extension_id'] : '';
                    $revId = is_scalar($ext_info['revision_id'] ?? null) ? $ext_info['revision_id'] : '';
                    $revDesc = is_scalar($ext_info['revision_description'] ?? null) ? (string) $ext_info['revision_description'] : '';
                    $dlUrl = is_scalar($ext_info['download_url'] ?? null) ? (string) $ext_info['download_url'] : '';
                    array_push($updates_extension[$type], [
                        'ID' => $extId, 'REVISION_ID' => $revId, 'EXT_ID' => $ext_id, 'EXT_NAME' => is_scalar($fs_ext_item['name'] ?? null) ? $fs_ext_item['name'] : '',
                        'EXT_URL' => PEM_URL . '/extension_view.php?eid=' . (string) $extId . '#changelog',
                        'REV_DESC' => trim($revDesc, " \n\r"),
                        'CURRENT_VERSION' => $extVersion, 'NEW_VERSION' => $revName,
                        'URL_DOWNLOAD' => $dlUrl . '&origin=piwigo_download',
                        'IGNORED' => in_array($ext_id, $updates_ignored_for_type),
                    ]);
                }
                if (count($updates_ignored_for_type) > 0) {
                    $show_reset = true;
                }
            }
        }

        $ext_type = $pageStr == 'updates' ? 'extensions' : $pageStr;
        $tpl->assign('UPDATES_EXTENSION', $updates_extension);
        $tpl->assign('SHOW_RESET', $show_reset);
        $tpl->assign('PWG_TOKEN', $this->util->getPwgToken());
        $tpl->assign('EXT_TYPE', $ext_type);
        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('page_data_json', json_encode(['pwg_token' => $this->util->getPwgToken(), 'ext_type' => $ext_type, 'str_error_head' => Lang::t('ERROR'), 'str_error_msg' => Lang::t('an error happened'), 'str_restore' => Lang::t('Reset ignored updates'), 'str_confirm_update_all' => Lang::t('Are you sure you want to update all extensions?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'updates_ext.latte');
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Updates'));
    }

    // ── updates_pwg ───────────────────────────────────────────────────────────

    private function updatesPwg(): void
    {
        $tpl = TemplateRegistry::current();
        $updatedVersion = null;

        if (!Config::enableCoreUpdate()) {
            throw new ConfigException('Piwigo core update system is disabled');
        }

        $getStepRaw = $_GET['step'] ?? null;
        $step = is_numeric($getStepRaw) ? (int) $getStepRaw : 0;
        [$ct_env, $ct_build_version] = $this->stringUtil->getContainerInfo();

        if ('Official' === $ct_env) {
            $tpl->assign(['CONTAINER_VERSION' => $ct_build_version, 'DOCKER_UPDATE_GUIDE_URL' => PHPWG_URL . '/guide-update-docker']);
            $this->util->checkInputParameter('to', $_GET, false, '/^\d+\.\d+\.\d+[a-z]?$/');
            $rawUpgradeTo   = $_GET['to'] ?? null;
            $upgrade_to_raw = is_string($rawUpgradeTo) ? $rawUpgradeTo : '';
            $upgrade_to     = $upgrade_to_raw !== '' ? (string) preg_replace('/[a-z]$/', '', $upgrade_to_raw) : '';
        } else {
            $this->util->checkInputParameter('to', $_GET, false, '/^\d+\.\d+\.\d+$/');
            $rawUpgradeToAlt = $_GET['to'] ?? null;
            $upgrade_to = is_string($rawUpgradeToAlt) ? $rawUpgradeToAlt : '';
        }

        $updates      = Kernel::service(Updates::class);
        $new_versions = $updates->getPiwigoNewVersions();

        if ($step == 0) {
            if (isset($new_versions['minor']) && isset($new_versions['major'])) {
                $step = 1;
                $upgrade_to = is_string($new_versions['major']) ? $new_versions['major'] : '';
            } elseif (isset($new_versions['minor'])) {
                $step = 2;
                $upgrade_to = is_string($new_versions['minor']) ? $new_versions['minor'] : '';
            } elseif (isset($new_versions['major'])) {
                $step = 3;
                $upgrade_to = is_string($new_versions['major']) ? $new_versions['major'] : '';
            }
            $checkVersionRaw = $new_versions['piwigo.org-checked'] ?? null;
            $devVersionRaw   = $new_versions['is_dev'] ?? null;
            $tpl->assign('CHECK_VERSION', is_scalar($checkVersionRaw) ? (string) $checkVersionRaw : '');
            $tpl->assign('DEV_VERSION', is_scalar($devVersionRaw) ? (string) $devVersionRaw : '');
        }

        if ($step == 2 && $this->permissionService->isWebmaster()) {
            if (isset($_POST['submit']) && isset($_POST['upgrade_to'])) {
                $rawUpgradeTo2 = $_POST['upgrade_to'];
                $updatedVersion = Kernel::service(Updates::class)->upgradeTo(is_string($rawUpgradeTo2) ? $rawUpgradeTo2 : '', $step);
            }
        }

        if ($step == 3 && $this->permissionService->isWebmaster()) {
            if (isset($_POST['submit']) && isset($_POST['upgrade_to'])) {
                $rawUpgradeTo3 = $_POST['upgrade_to'];
                $updatedVersion = Kernel::service(Updates::class)->upgradeTo(is_string($rawUpgradeTo3) ? $rawUpgradeTo3 : '', $step);
            }
            $updates->getMergedExtensions($upgrade_to);
            $updates->getServerExtensions($upgrade_to);
            $tpl->assign('missing', $updates->missing);
        }

        $phpVersionResult = phpversion();
        /** @var string|false $phpVersionResult */
        $phpVersion       = $phpVersionResult === false ? '0.0.0' : $phpVersionResult;
        if (isset($new_versions['minor_php']) && version_compare($phpVersion, is_string($new_versions['minor_php']) ? $new_versions['minor_php'] : '', '<')) {
            $minorPhpRaw = $new_versions['minor_php'];
            $tpl->assign('MINOR_RELEASE_PHP_REQUIRED', is_scalar($minorPhpRaw) ? (string) $minorPhpRaw : '');
        }
        if (isset($new_versions['major_php']) && version_compare($phpVersion, is_string($new_versions['major_php']) ? $new_versions['major_php'] : '', '<')) {
            $majorPhpRaw = $new_versions['major_php'];
            $tpl->assign('MAJOR_RELEASE_PHP_REQUIRED', is_scalar($majorPhpRaw) ? (string) $majorPhpRaw : '');
        }

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $pageUpdatedVersion = $updatedVersion ?? AppInfo::VERSION;
        $tpl->assign(['STEP' => $step, 'PIWIGO_CURRENT_VERSION' => $pageUpdatedVersion, 'UPGRADE_TO' => $upgrade_to]);

        if (isset($new_versions['minor'])) {
            $minor_ver = is_string($new_versions['minor']) ? $new_versions['minor'] : '';
            $tpl->assign(['MINOR_VERSION' => $minor_ver, 'MINOR_RELEASE_URL' => (('Official' === $ct_env) ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . (string) preg_replace('/\./', '', $minor_ver) : PHPWG_URL . '/releases/' . $minor_ver)]);
        }

        if (isset($new_versions['major'])) {
            $major_ver = is_string($new_versions['major']) ? $new_versions['major'] : '';
            $majorVerNoDots = preg_replace('/\./', '', $major_ver) ?? '';
            $majorVerNoAlpha = preg_replace('/[a-z]$/', '', $major_ver) ?? '';
            $tpl->assign(['MAJOR_VERSION' => $major_ver, 'MAJOR_RELEASE_URL' => PHPWG_URL . '/releases/' . (('Official' === $ct_env) ? substr($major_ver, 0, -1) : $major_ver), 'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . $majorVerNoDots, 'MAJOR_VERSION_PWG' => $majorVerNoAlpha]);
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Updates'));
        $tpl->assign('page_data_json', json_encode(['str_are_you_sure' => Lang::t('Are you sure?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'updates_pwg.latte');
    }

    // ── extend_for_templates ──────────────────────────────────────────────────

    private function extendForTemplates(): void
    {
        $tpl = TemplateRegistry::current();
        $tpl_extension = StringUtil::safeUnserialize(Config::extentsForTemplates() ?? '');
        $new_extensions = $this->adminService->getExtents();

        $relevant_parameters = ['----------', 'category', 'favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats', 'created-monthly-calendar', 'posted-monthly-calendar', 'search', 'flat', 'list', 'tags'];
        $permalinks = array_column($this->conn->executeQuery('SELECT permalink FROM ' . Tables::categories() . ' WHERE permalink IS NOT NULL')->fetchAllAssociative(), 'permalink');
        $relevant_parameters = array_merge($relevant_parameters, $permalinks);

        $eligible_templates = ['----------' => 'N/A', 'about.tpl' => 'about', 'comments.tpl' => 'comments', 'comment_list.tpl' => 'comment_list', 'footer.tpl' => 'tail', 'header.tpl' => 'header', 'identification.tpl' => 'identification', 'index.tpl' => 'index', 'mainpage_categories.tpl' => 'index_category_thumbnails', 'menubar.tpl' => 'menubar', 'menubar_categories.tpl' => 'mbCategories', 'menubar_identification.tpl' => 'mbIdentification', 'menubar_links.tpl' => 'mbLinks', 'menubar_menu.tpl' => 'mbMenu', 'menubar_specials.tpl' => 'mbSpecials', 'menubar_tags.tpl' => 'mbTags', 'month_calendar.tpl' => 'month_calendar', 'navigation_bar.tpl' => 'navbar', 'nbm.tpl' => 'nbm', 'notification.tpl' => 'notification', 'password.tpl' => 'password', 'picture.tpl' => 'picture', 'picture_content.tpl' => 'default_content', 'picture_nav_buttons.tpl' => 'picture_nav_buttons', 'popuphelp.tpl' => 'popuphelp', 'profile.tpl' => 'profile', 'profile_content.tpl' => 'profile_content', 'redirect.tpl' => 'redirect', 'register.tpl' => 'register', 'search.tpl' => 'search', 'slideshow.tpl' => 'slideshow', 'tags.tpl' => 'tags', 'thumbnails.tpl' => 'index_thumbnails'];

        $flip_templates      = array_flip($eligible_templates);
        $available_templates = array_merge(['N/A' => '----------'], $this->adminService->getDirs(PHPWG_ROOT_PATH . 'themes'));

        if (isset($_POST['submit'])) {
            $reptplRaw   = $_POST['reptpl'] ?? null;
            $reptpl_arr  = is_array($reptplRaw) ? $reptplRaw : [];
            $originalRaw = $_POST['original'] ?? null;
            $original_arr = is_array($originalRaw) ? $originalRaw : [];
            $urlRaw      = $_POST['url'] ?? null;
            $url_arr     = is_array($urlRaw) ? $urlRaw : [];
            $boundRaw    = $_POST['bound'] ?? null;
            $bound_arr   = is_array($boundRaw) ? $boundRaw : [];
            $replacements = [];
            $i = 0;
            while (isset($reptpl_arr[$i])) {
                $newtpl     = is_string($reptpl_arr[$i]) ? $reptpl_arr[$i] : '';
                $origRaw    = $original_arr[$i] ?? null;
                $original   = is_string($origRaw) ? $origRaw : '';
                $handle     = $eligible_templates[$original] ?? 'N/A';
                $urlRaw2    = $url_arr[$i] ?? null;
                $url_keyword = is_string($urlRaw2) ? $urlRaw2 : '';
                if ($url_keyword == '----------') {
                    $url_keyword = 'N/A';
                }
                $boundRaw2  = $bound_arr[$i] ?? null;
                $bound_tpl  = is_string($boundRaw2) ? $boundRaw2 : '';
                if ($bound_tpl == '----------') {
                    $bound_tpl = 'N/A';
                }
                if ($handle != 'N/A') {
                    $replacements[$newtpl] = [$handle, $url_keyword, $bound_tpl];
                }
                $i++;
            }
            Config::override('extents_for_templates', serialize($replacements));
            $tpl_extension = $replacements;
            $this->configService->confUpdateParam('extents_for_templates', Config::extentsForTemplates());
            PageState::current()->addInfo(Lang::t('Templates configuration has been recorded.'));
        }

        foreach ($tpl_extension as $file => $conditions) {
            $fileStr = (string) $file;
            if (!in_array($fileStr, $new_extensions)) {
                unset($tpl_extension[$file]);
            } else {
                $new_extensions = array_diff($new_extensions, [$fileStr]);
            }
        }
        foreach ($new_extensions as $file) {
            $tpl_extension[$file] = ['N/A', 'N/A', 'N/A'];
        }

        $tpl->assign(['U_HELP' => $this->urlGenerator->adminPopupHelp('extend_for_templates')]);

        ksort($tpl_extension);
        foreach ($tpl_extension as $file => $conditions) {
            if (!is_array($conditions)) {
                continue;
            }
            $handle      = is_scalar($conditions[0] ?? null) ? (string) $conditions[0] : 'N/A';
            $url_keyword = is_scalar($conditions[1] ?? null) ? (string) $conditions[1] : 'N/A';
            $bound_tpl   = is_scalar($conditions[2] ?? null) ? (string) $conditions[2] : 'N/A';
            $tpl->append('extents', ['replacer' => $file, 'url_parameter' => $relevant_parameters, 'original_tpl' => array_keys($eligible_templates), 'bound_tpl' => $available_templates, 'selected_tpl' => $flip_templates[$handle] ?? '', 'selected_url' => $url_keyword, 'selected_bound' => $bound_tpl]);
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Extend for templates'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'extend_for_templates.latte');
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function cmpThemes(array $a, array $b): int
    {
        $s = ['active' => 0, 'inactive' => 1];
        if (!empty($a['IS_DEFAULT'])) {
            return -1;
        }
        if (!empty($b['IS_DEFAULT'])) {
            return 1;
        }
        $a_state = is_string($a['STATE'] ?? null) ? $a['STATE'] : '';
        $b_state = is_string($b['STATE'] ?? null) ? $b['STATE'] : '';
        $a_name  = is_scalar($a['NAME'] ?? null) ? (string) $a['NAME'] : '';
        $b_name  = is_scalar($b['NAME'] ?? null) ? (string) $b['NAME'] : '';
        if ($a_state == $b_state) {
            return strcasecmp($a_name, $b_name);
        }
        return (($s[$a_state] ?? 0) >= ($s[$b_state] ?? 0) ? 1 : -1);
    }
}
