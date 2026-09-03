<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\CatalogLanguageRow;
use Piwigo\Admin\Projection\LanguagesNewView;
use Piwigo\Admin\Request\LanguagesNewInstallRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Ported from admin/languages_new.php (the "new" tab of the "languages" page
 * slug, dispatched by LanguagesSubController) -- browse the PEM catalog and
 * install a new language.
 *
 * Already correctly protected before this port -- its one real mutation
 * (isset($_GET['revision']), install + auto-activate)
 * already gates on is_webmaster() and check_pwg_token() -- no CSRF fix
 * needed here, unlike LanguagesInstalledPageRenderer.
 */
final readonly class LanguagesNewPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private CurrentLogger $currentLogger,
        private PageState $pageState,
        private ActivityService $activityService,
        private UserService $userService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private CurrentUser $currentUser,
        private Paths $paths,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    /**
     * $pageSlug and $tab are explicit params rather than
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (LanguagesSubController) already knows both values statically/
     * locally (it's the only class registered for the 'languages' slug
     * in config/admin_pages.php, and it already computes its own $tab
     * local before dispatching here).
     */
    public function render(string $pageSlug, string $tab): AdminPageResult
    {
        if (! $this->currentConfig->enableExtensionsInstall) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $extension_repository = new ExtensionRepository($this->entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->paths, $this->currentUser, $this->eventDispatcher, $this->pluginRegistry, $this->themeRegistry, $this->entityManager);

        $languages_dir = $this->paths->root . 'language';
        if (! is_writable($languages_dir)) {
            $this->pageState->addError($this->lang->t('Add write access to the "%s" directory', 'language'));
        }

        $languagesNewInstall = LanguagesNewInstallRequest::fromGlobals();

        if ($languagesNewInstall->revision !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                $this->csrfService
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $revision = $languagesNewInstall->revision;

                $extraction = $pem_catalog->extractArchive(ExtensionType::Language, 'install', $revision, '');
                $install_status = $extraction->status;

                // extract_language_files() legacy quirk: a successful install
                // auto-activates the new language immediately (languages have no
                // separate "installed but inactive" state) -- reproduced here since
                // PemCatalog::extractArchive() only extracts, it doesn't know about
                // the lifecycle state machine.
                if ($install_status === 'ok' && $extraction->id !== null) {
                    $fs_language_entry = $extension_scanner->scanLanguages($this->paths, $this->currentConfig, $this->entityManager)[$extraction->id] ?? null;
                    $extension_lifecycle->performAction(ExtensionType::Language, 'activate', $extraction->id, $fs_language_entry);
                }

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status);
            }
        }

        if ($languagesNewInstall->installStatus !== null) {
            $installstatus = $languagesNewInstall->installStatus;

            match ($installstatus) {
                'ok' => $this->pageState->addInfo($this->lang->t('Language has been successfully installed')),
                'temp_path_error' => $this->pageState->addError($this->lang->t('Can\'t create temporary file.')),
                'dl_archive_error' => $this->pageState->addError($this->lang->t('Can\'t download archive.')),
                'archive_error' => $this->pageState->addError($this->lang->t('Can\'t read or extract archive.')),
                // A plain string, not Html -- HtmlService::flushMessageList()
                // htmlspecialchars()'s it once at flush time (P59 Batch 6), so
                // $installstatus must stay raw here to avoid a double-escape.
                default => $this->pageState->addError($this->lang->t('An error occured during extraction (%s).', $installstatus)),
            };
        }

        $fs_language_ids = [];
        foreach ($extension_scanner->scanLanguages($this->paths, $this->currentConfig, $this->entityManager) as $fs_language) {
            if ($fs_language->extension !== null) {
                $fs_language_ids[] = $fs_language->extension;
            }
        }
        $server_languages = $pem_catalog->getServerExtensions(ExtensionType::Language, $fs_language_ids, true);

        $tpl_languages = [];

        if ($server_languages !== null) {
            // Must match the type-specific mirror getServerExtensions()
            // above actually fetched from (PemCatalog's own
            // RequestBootstrap::pemUrl($type) call) -- the bare, untyped
            // pemUrl() resolves a different base (the generic
            // alternativePemUrl override, or the real, deliberately-
            // unreachable AppInfo::URL default), which would 404 every
            // EXT_URL "Website" link below even when the language catalog
            // itself loaded fine from a real per-type mirror.
            $pem_base_url = RequestBootstrap::pemUrl(ExtensionType::Language);

            foreach ($server_languages as $language) {
                // $language comes from an untyped unserialize() of a remote PEM
                // payload (see languages::get_server_languages()); only cast
                // scalars actually safe to stringify, treat anything else as empty
                // (same pattern as languages.class.php::extension_name_compare()).
                $revision_date = $language['revision_date'] ?? null;
                $revision_date = is_scalar($revision_date) ? (string) $revision_date : '';

                $revision_id = $language['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id) ? (string) $revision_id : '';

                $extension_id = $language['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id) ? (string) $extension_id : '';

                $download_url = $language['download_url'] ?? null;
                $download_url = is_scalar($download_url) ? (string) $download_url : '';

                [$date] = explode(' ', $revision_date);

                // $revision_id/$download_url are untyped remote-PEM-payload
                // data (this loop's own comment above), reaching
                // languages_new.latte as bare
                // {$language->installUrl|noescape}/{$language->downloadUrl|noescape}
                // prints with no escaping anywhere else in this method --
                // htmlspecialchars() them explicitly here, the same fix as
                // P59 Batch 0's json_encode findings (confirmed real XSS
                // via a crafted PEM catalog response otherwise).
                $url_auto_install = htmlspecialchars($base_url)
                  . '&revision=' . htmlspecialchars($revision_id)
                  . '&pwg_token=' . $this->csrfService->getToken()
                ;

                $tpl_languages[] = new CatalogLanguageRow(
                    name: self::text($language['extension_name'] ?? null),
                    description: self::text($language['extension_description'] ?? null),
                    // $extension_id is untyped remote-PEM-payload data,
                    // reaching languages_new.latte as a bare
                    // {$language->url|noescape} print (P59 Batch 5, same
                    // fix as installUrl above).
                    url: $pem_base_url . '/extension_view.php?eid=' . htmlspecialchars($extension_id),
                    version: self::text($language['revision_name'] ?? null),
                    versionDescription: self::text($language['revision_description'] ?? null),
                    date: $date,
                    author: self::text($language['author_name'] ?? null),
                    installUrl: $url_auto_install,
                    downloadUrl: htmlspecialchars($download_url) . '&origin=piwigo_download',
                );
            }
        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }
        $adminContent = $this->renderer->render(new LanguagesNewView(
            isWebmaster: $this->accessControl->isWebmaster() ? 1 : 0,
            languages: $tpl_languages,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Languages'),
        );
    }

    /**
     * A manifest value is whatever the mirrored JSON happened to hold, so
     * a field this page renders as text is a string only by convention.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
