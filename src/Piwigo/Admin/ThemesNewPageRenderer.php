<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\ThemesNewView;
use Piwigo\Admin\Request\ThemesNewInstallRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
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
use Piwigo\Template\Renderer;
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
final readonly class ThemesNewPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private PageState $pageState,
        private ActivityService $activityService,
        private PreferencesService $preferencesService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private CurrentUser $currentUser,
        private Paths $paths,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    /**
     * $pageSlug and $tab are explicit params instead of
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (ThemesSubController) already knows both values statically/locally
     * (it's the only class registered for the 'themes' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here).
     */
    public function render(string $pageSlug, string $tab): AdminPageResult
    {
        if (! $this->currentConfig->enableExtensionsInstall) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();

        $themes_dir = $this->paths->themes;
        if (! is_writable($themes_dir)) {
            $this->pageState->addError($this->lang->t('Add write access to the "%s" directory', 'themes'));
        }

        $themesNewInstall = ThemesNewInstallRequest::fromGlobals();

        if ($themesNewInstall->revision !== null and $themesNewInstall->extension !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                $this->csrfService
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $extraction = $pem_catalog->extractArchive(ExtensionType::Theme, 'install', $themesNewInstall->revision, $themesNewInstall->extension);
                $install_status = $extraction->status;
                $theme_id = $extraction->id;

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . ($theme_id ?? ''));
            }
        }

        if ($themesNewInstall->installStatus !== null) {
            switch ($themesNewInstall->installStatus) {
                case 'ok':
                    $this->pageState->addInfo($this->lang->t('Theme has been successfully installed'));

                    $installed_theme_id = $themesNewInstall->installedThemeId;
                    $installed_fs_theme = $installed_theme_id !== null ? ($extension_scanner->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager)[$installed_theme_id] ?? null) : null;
                    if ($installed_fs_theme !== null) {
                        $this->activityService->record('system', ActivitySystem::Theme, 'install', [
                            'theme_id' => $installed_theme_id,
                            'version' => $installed_fs_theme->version,
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
                    // A plain string, not Html -- HtmlService::flushMessageList()
                    // htmlspecialchars()'s it once at flush time (P59 Batch 6), so
                    // $installStatus must stay raw here to avoid a double-escape.
                    $this->pageState->addError(
                        $this->lang->t(
                            'An error occured during extraction (%s).',
                            $themesNewInstall->installStatus
                        )
                    );
            }
        }

        $fs_theme_ids = [];
        foreach ($extension_scanner->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager) as $fs_theme) {
            if ($fs_theme->extension !== null) {
                $fs_theme_ids[] = $fs_theme->extension;
            }
        }
        $server_themes = $pem_catalog->getServerExtensions(ExtensionType::Theme, $fs_theme_ids, true);

        $new_themes = [];
        if ($server_themes !== null) { // only new themes
            // Must match the type-specific mirror getServerExtensions()
            // above actually fetched from -- same reasoning as
            // PluginsNewPageRenderer/LanguagesNewPageRenderer's own
            // pem_base_url.
            $pem_base_url = RequestBootstrap::pemUrl(ExtensionType::Theme);

            foreach ($server_themes as $theme) {
                // server_themes entries come from an untyped unserialize() of a
                // remote PEM payload (themes::get_server_themes()); narrow the
                // fields used in a typed context below rather than trusting the
                // external payload's shape.
                $revision_id_raw = $theme['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
                $extension_id_raw = $theme['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

                // $revision_id/$extension_id are untyped remote-PEM-payload
                // data (this loop's own comment above), reaching
                // themes_new.latte as a bare {$theme['install_url']|noescape}
                // print with no escaping anywhere else in this method --
                // htmlspecialchars() them explicitly here, the same fix as
                // P59 Batch 0's json_encode findings (confirmed real
                // XSS via a crafted PEM catalog response otherwise).
                $url_auto_install = htmlspecialchars($base_url)
                  . '&revision=' . htmlspecialchars($revision_id)
                  . '&extension=' . htmlspecialchars($extension_id)
                  . '&pwg_token=' . $this->csrfService->getToken()
                ;

                // 'screenshot_url' matches the real upstream PEM wire
                // format this fork no longer talks to (PemCatalog's own
                // docblock) -- the actual local-mirror manifest.json
                // field is 'thumbnail' (a bare filename inside the
                // sibling repo's own docroot, same convention
                // piwigo16-ext/api/get_revision_list.php's own
                // screenshot_url computation already uses:
                // $base_url . '/' . $ext['thumbnail']).
                $thumbnail_raw = $theme['thumbnail'] ?? null;
                $screenshot = is_string($thumbnail_raw) && $thumbnail_raw !== ''
                    ? $pem_base_url . '/' . $thumbnail_raw
                    : '';

                $new_themes[] = [
                    'name' => $theme['extension_name'],
                    'screenshot' => $screenshot,
                    'install_url' => $url_auto_install,
                ];
            }
        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }

        $admin_theme_pref = $this->preferencesService->getAdminThemePref() ?? $this->currentConfig->adminTheme;
        $adminContent = $this->renderer->render(new ThemesNewView(
            defaultScreenshot: $this->urlService->getRootUrl() . 'themes/admin/' . $admin_theme_pref . '/images/missing_screenshot.png',
            newThemes: $new_themes,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Themes'),
        );
    }
}
