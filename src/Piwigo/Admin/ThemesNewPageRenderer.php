<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\ThemesNewPageContext;
use Piwigo\Admin\Request\ThemesNewInstallRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;

/**
 * Ported from admin/themes_new.php (the "new" tab of the "themes" page slug,
 * dispatched by ThemesSubController) -- browse the PEM catalog and install a
 * new theme.
 *
 * This page's one mutation (install, gated on $revision/$extension both
 * being set) requires webmaster status and a valid CSRF token before
 * calling PemCatalog::extractArchive() -- unlike ThemesInstalledPageRenderer.
 */
final class ThemesNewPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CurrentLogger $currentLogger,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly PreferencesService $preferencesService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly CurrentUser $currentUser,
        private readonly Paths $paths,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    /**
     * $pageSlug and $tab are explicit params instead of
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (ThemesSubController) already knows both values statically/locally
     * (it's the only class registered for the 'themes' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here).
     */
    public function render(string $pageSlug, string $tab): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableExtensionsInstall()) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->currentUser, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();

        // +-----------------------------------------------------------------------+
        // |                           setup check                                 |
        // +-----------------------------------------------------------------------+

        $themes_dir = $this->paths->themes;
        if (! is_writable($themes_dir)) {
            $this->pageState->addError($this->lang->t('Add write access to the "%s" directory', 'themes'));
        }

        // +-----------------------------------------------------------------------+
        // |                       perform installation                            |
        // +-----------------------------------------------------------------------+

        $themesNewInstall = ThemesNewInstallRequest::fromGlobals();

        if ($themesNewInstall->revision !== null and $themesNewInstall->extension !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $extraction = $pem_catalog->extractArchive(ExtensionType::Theme, 'install', $themesNewInstall->revision, $themesNewInstall->extension);
                $install_status = $extraction->status;
                $theme_id = $extraction->id;

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . $theme_id);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                        installation result                            |
        // +-----------------------------------------------------------------------+

        if ($themesNewInstall->installStatus !== null) {
            switch ($themesNewInstall->installStatus) {
                case 'ok':
                    $this->pageState->addInfo($this->lang->t('Theme has been successfully installed'));

                    $installed_theme_id = $themesNewInstall->installedThemeId;
                    $installed_fs_theme = $installed_theme_id !== null ? ($extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig)[$installed_theme_id] ?? null) : null;
                    if ($installed_fs_theme !== null) {
                        $this->activityService->record('system', ActivitySystem::Theme, 'install', [
                            'theme_id' => $installed_theme_id,
                            'version' => $installed_fs_theme['version'],
                        ]);
                    }
                    break;

                case 'temp_path_error':
                    $this->pageState->addError($this->lang->t('Can\'t create temporary file.'));
                    break;

                case 'dl_archive_error':
                    $this->pageState->addError($this->lang->t('Can\'t download archive.'));
                    break;

                case 'archive_error':
                    $this->pageState->addError($this->lang->t('Can\'t read or extract archive.'));
                    break;

                default:
                    $this->pageState->addError(
                        $this->lang->t(
                            'An error occured during extraction (%s).',
                            htmlspecialchars($themesNewInstall->installStatus)
                        )
                    );
            }
        }

        // +-----------------------------------------------------------------------+
        // |                          template output                              |
        // +-----------------------------------------------------------------------+

        $template->set_filenames([
            'themes' => 'themes_new.tpl',
        ]);

        $fs_theme_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig) as $fs_theme) {
            $extension = $fs_theme['extension'] ?? null;
            if (is_scalar($extension)) {
                $fs_theme_ids[] = (string) $extension;
            }
        }
        $server_themes = $pem_catalog->getServerExtensions(ExtensionType::Theme, $fs_theme_ids, true);

        $new_themes = [];
        if ($server_themes !== null) { // only new themes
            foreach ($server_themes as $theme) {
                // server_themes entries come from an untyped unserialize() of a
                // remote PEM payload (themes::get_server_themes()); narrow the
                // fields used in a typed context below rather than trusting the
                // external payload's shape.
                $revision_id_raw = $theme['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
                $extension_id_raw = $theme['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;extension=' . $extension_id
                  . '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken()
                ;

                $new_themes[] = [
                    'name' => $theme['extension_name'],
                    'thumbnail' => (key_exists('thumbnail_src', $theme)) ? $theme['thumbnail_src'] : '',
                    'screenshot' => (key_exists('screenshot_url', $theme)) ? $theme['screenshot_url'] : '',
                    'install_url' => $url_auto_install,
                ];
            }
        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }

        $admin_theme_pref = $this->preferencesService->getAdminThemePref() ?? $this->currentConfig->adminTheme();
        $template->assignContext(new ThemesNewPageContext(
            defaultScreenshot: $this->urlService->getRootUrl() . 'themes/admin/' . $admin_theme_pref . '/images/missing_screenshot.png',
            adminPageTitle: $this->lang->t('Themes'),
            newThemes: $new_themes,
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }
}
