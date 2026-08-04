<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Template\Template;
use Piwigo\Users\UserService;

/**
 * Ported from admin/languages_installed.php (the "installed" tab of the
 * "languages" page slug, dispatched by LanguagesSubController) -- lists
 * installed languages and handles activate/deactivate/set_default/delete.
 *
 * P23 sub-batch 6i-1 fix: this file's action-handling block was already
 * gated on is_webmaster() and already allowlisted 'action'/'language' via
 * check_input_parameter(), but had zero check_pwg_token() call anywhere --
 * and ExtensionLifecycle::performAction() itself does no CSRF check of its
 * own either, so a crafted admin.php?page=languages&action=delete&language=X
 * GET request (an <img> tag on any page, no interaction beyond an active
 * webmaster session) could delete an active language. Fixed the same way
 * PictureModifyPageRenderer closed the sync_metadata gap in 6d: added
 * check_pwg_token() right after the existing guards, and embedded a real
 * token into $language['u_action']'s own add_url_params() call below --
 * languages_installed.tpl's 4 action links (activate/deactivate/
 * set_default/delete) all append '&action=X' onto that same u_action base,
 * so this one call site fixes all 4 links at once.
 */
final class LanguagesInstalledPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Activity\ActivityService $activityService,
        private readonly UserService $userService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    /**
     * Legacy Coupling Retirement Track A batch A5.2f: $pageSlug is an
     * explicit param instead of `global $page['page'];` -- the one real
     * caller (LanguagesSubController) already knows its own fixed page
     * slug statically (it's the only class registered for the
     * 'languages' slug in config/admin_pages.php). Also drops a
     * confirmed-dead `global $conf;` found incidentally (never
     * referenced anywhere in this method).
     */
    public function render(string $pageSlug): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $template->set_filenames([
            'languages' => 'languages_installed.tpl',
        ]);

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $conn = DbConnection::build();
        $extension_repository = new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($conn));
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger);
        $extension_scanner = new ExtensionScanner();
        $plugin_migration_repo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Admin\Extensions\PluginMigrationEntity::class);
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $plugin_migration_repo, $this->activityService, $this->userService, $this->htmlRenderer);

        $fs_languages = $extension_scanner->scan(ExtensionType::Language, $this->urlService, $this->lang);
        $db_languages = $extension_repository->findAll(ExtensionType::Language);

        // --------------------------------------------------perform requested actions
        $languagesAction = Request\LanguagesInstalledActionRequest::fromGlobals('/^(' . join('|', array_keys($fs_languages)) . ')$/');

        if ($languagesAction->action !== null and $languagesAction->languageId !== null and $this->accessControl->isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $fs_language_entry = $fs_languages[$languagesAction->languageId] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Language, $languagesAction->action, $languagesAction->languageId, $fs_language_entry);
            $this->pageState->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                $this->redirectService->redirect($base_url);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+
        $default_language = $this->userService->getDefaultLanguage();

        $tpl_languages = [];

        foreach ($fs_languages as $language_id => $language) {
            $language['u_action'] = $this->urlService->addUrlParams($base_url, [
                'language' => $language_id,
                'pwg_token' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]);

            if (in_array($language_id, array_keys($db_languages), true)) {
                $language['state'] = 'active';
                $language['deactivable'] = true;

                if (count($db_languages) <= 1) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = $this->lang->t('Impossible to deactivate this language, you need at least one language.');
                }

                if ($language_id === $default_language) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = $this->lang->t('Impossible to deactivate this language, first set another language as default.');
                }
            } else {
                $language['state'] = 'inactive';
            }

            if ($language_id === $default_language) {
                $language['is_default'] = true;
                array_unshift($tpl_languages, $language);
            } else {
                $language['is_default'] = false;
                $tpl_languages[] = $language;
            }
        }

        $template->assign(
            [
                'languages' => $tpl_languages,
            ]
        );
        $template->append('language_states', 'active');
        $template->append('language_states', 'inactive');

        $missing_language_ids = array_diff(
            array_keys($db_languages),
            array_keys($fs_languages)
        );

        foreach ($missing_language_ids as $language_id) {
            $extension_repository->reassignUsersFromLanguage($language_id, $this->userService->getDefaultLanguage());
            $extension_repository->delete(ExtensionType::Language, $language_id);
        }

        $template->assign('isWebmaster', ($this->accessControl->isWebmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Languages'));
        $template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', \Piwigo\Config\CurrentConfig::enableExtensionsInstall());

        $template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }
}
