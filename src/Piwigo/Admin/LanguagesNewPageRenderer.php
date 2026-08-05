<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\PluginMigrationEntity;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Request\LanguagesNewInstallRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Ported from admin/languages_new.php (the "new" tab of the "languages" page
 * slug, dispatched by LanguagesSubController) -- browse the PEM catalog and
 * install a new language.
 *
 * Already correctly protected before this port (confirmed by direct read):
 * its one real mutation (isset($_GET['revision']), install + auto-activate)
 * already gates on is_webmaster() and check_pwg_token() -- no CSRF fix
 * needed here, unlike LanguagesInstalledPageRenderer.
 */
final class LanguagesNewPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CurrentLogger $currentLogger,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly WsContext $wsContext,
        private readonly CurrentUser $currentUser,
    ) {}

    /**
     * Legacy Coupling Retirement Track A: $pageSlug (batch A5.2f) and
     * $tab (batch A5.2h) are explicit params instead of
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (LanguagesSubController) already knows both values statically/
     * locally (it's the only class registered for the 'languages' slug
     * in config/admin_pages.php, and it already computes its own $tab
     * local before dispatching here). Also drops a confirmed-dead
     * `global $conf;` found incidentally (never referenced anywhere in
     * this method).
     */
    public function render(string $pageSlug, string $tab): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableExtensionsInstall()) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $template->set_filenames([
            'languages' => 'languages_new.tpl',
        ]);

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $extension_repository = new ExtensionRepository(EntityManagerFactory::build(DbConnection::build()));
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->currentUser);
        $extension_scanner = new ExtensionScanner();
        $plugin_migration_repo = EntityManagerFactory::build(DbConnection::build())->getRepository(PluginMigrationEntity::class);
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $plugin_migration_repo, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->wsContext, $this->accessControl);

        // +-----------------------------------------------------------------------+
        // |                           setup check                                 |
        // +-----------------------------------------------------------------------+

        $languages_dir = CurrentPaths::get()->root . 'language';
        if (! is_writable($languages_dir)) {
            $this->pageState->addError($this->lang->t('Add write access to the "%s" directory', 'language'));
        }

        // +-----------------------------------------------------------------------+
        // |                       perform installation                            |
        // +-----------------------------------------------------------------------+

        $languagesNewInstall = LanguagesNewInstallRequest::fromGlobals();

        if ($languagesNewInstall->revision !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                new CsrfService()
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $revision = $languagesNewInstall->revision;

                $extraction = $pem_catalog->extractArchive(ExtensionType::Language, 'install', $revision, '');
                $install_status = $extraction['status'];

                // extract_language_files() legacy quirk: a successful install
                // auto-activates the new language immediately (languages have no
                // separate "installed but inactive" state) -- reproduced here since
                // PemCatalog::extractArchive() only extracts, it doesn't know about
                // the lifecycle state machine.
                if ($install_status === 'ok' && $extraction['id'] !== null) {
                    $fs_language_entry = $extension_scanner->scan(ExtensionType::Language, $this->urlService, $this->lang)[$extraction['id']] ?? null;
                    $extension_lifecycle->performAction(ExtensionType::Language, 'activate', $extraction['id'], $fs_language_entry);
                }

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                        installation result                            |
        // +-----------------------------------------------------------------------+
        if ($languagesNewInstall->installStatus !== null) {
            $installstatus = $languagesNewInstall->installStatus;

            match ($installstatus) {
                'ok' => $this->pageState->addInfo($this->lang->t('Language has been successfully installed')),
                'temp_path_error' => $this->pageState->addError($this->lang->t('Can\'t create temporary file.')),
                'dl_archive_error' => $this->pageState->addError($this->lang->t('Can\'t download archive.')),
                'archive_error' => $this->pageState->addError($this->lang->t('Can\'t read or extract archive.')),
                default => $this->pageState->addError($this->lang->t('An error occured during extraction (%s).', htmlspecialchars($installstatus))),
            };
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+
        $fs_language_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Language, $this->urlService, $this->lang) as $fs_language) {
            $extension = $fs_language['extension'] ?? null;
            if (is_scalar($extension)) {
                $fs_language_ids[] = (string) $extension;
            }
        }
        $server_languages = $pem_catalog->getServerExtensions(ExtensionType::Language, $fs_language_ids, true);

        if ($server_languages !== null) {
            $pem_base_url = RequestBootstrap::pemUrl();

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

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;pwg_token=' . new CsrfService()->getToken()
                ;

                $template->append('languages', [
                    'EXT_NAME' => $language['extension_name'],
                    'EXT_DESC' => $language['extension_description'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id,
                    'VERSION' => $language['revision_name'],
                    'VER_DESC' => $language['revision_description'],
                    'DATE' => $date,
                    'AUTHOR' => $language['author_name'],
                    'URL_INSTALL' => $url_auto_install,
                    'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
                ]);
            }
        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }
        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Languages'));
        $template->assign('isWebmaster', ($this->accessControl->isWebmaster()) ? 1 : 0);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }
}
