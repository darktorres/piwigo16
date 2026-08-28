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
use Piwigo\Admin\Projection\LanguageListRow;
use Piwigo\Admin\Projection\LanguagesInstalledView;
use Piwigo\Admin\Request\LanguagesInstalledActionRequest;
use Piwigo\Auth\AccessControl;
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
use Piwigo\Validation\InputValidator;

/**
 * Lists installed languages and handles activate/deactivate/set_default/
 * delete, dispatched from the "languages" page's "installed" tab by
 * LanguagesSubController.
 *
 * Action handling requires check_pwg_token() after the existing
 * webmaster/input guards. The token is embedded in
 * $language['u_action']'s own add_url_params() call below;
 * languages_installed.latte's 4 action links (activate/deactivate/
 * set_default/delete) all append '&action=X' onto that same u_action
 * base, so this one call site covers all 4 links.
 */
final readonly class LanguagesInstalledPageRenderer
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
        private InputValidator $inputValidator,
        private CurrentUser $currentUser,
        private Paths $paths,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    /**
     * $pageSlug is an explicit param: the one real caller
     * (LanguagesSubController) knows its own fixed page slug statically
     * -- it's the only class registered for the 'languages' slug in
     * config/admin_pages.php.
     */
    public function render(string $pageSlug): AdminPageResult
    {
        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $extension_repository = new ExtensionRepository($this->entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->paths, $this->currentUser, $this->eventDispatcher, $this->pluginRegistry, $this->themeRegistry, $this->entityManager);

        $fs_languages = $extension_scanner->scanLanguages($this->paths, $this->currentConfig, $this->entityManager);
        $db_languages = $extension_repository->findAllLanguages();

        // --------------------------------------------------perform requested actions
        $languagesAction = LanguagesInstalledActionRequest::fromGlobals('/^(' . join('|', array_keys($fs_languages)) . ')$/', $this->inputValidator);

        if ($languagesAction->action !== null and $languagesAction->languageId !== null and $this->accessControl->isWebmaster()) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $fs_language_entry = $fs_languages[$languagesAction->languageId] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Language, $languagesAction->action, $languagesAction->languageId, $fs_language_entry);
            $this->pageState->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                $this->redirectService->redirect($base_url);
            }
        }

        $default_language = $this->userService->getDefaultLanguage();

        $tpl_languages = [];

        foreach ($fs_languages as $language_id => $language) {
            $uAction = $this->urlService->addUrlParams($base_url, [
                'language' => $language_id,
                'pwg_token' => $this->csrfService
                    ->getToken(),
            ]);

            $deactivable = true;
            $deactivateTooltip = null;

            if (in_array($language_id, array_keys($db_languages), true)) {
                $state = 'active';

                if (count($db_languages) <= 1) {
                    $deactivable = false;
                    $deactivateTooltip = $this->lang->t('Impossible to deactivate this language, you need at least one language.');
                }

                if ($language_id === $default_language) {
                    $deactivable = false;
                    $deactivateTooltip = $this->lang->t('Impossible to deactivate this language, first set another language as default.');
                }
            } else {
                $state = 'inactive';
            }

            $isDefault = $language_id === $default_language;
            $tpl_language = new LanguageListRow(
                name: $language->name,
                uAction: $uAction,
                state: $state,
                deactivable: $deactivable,
                deactivateTooltip: $deactivateTooltip,
                isDefault: $isDefault,
            );

            if ($isDefault) {
                array_unshift($tpl_languages, $tpl_language);
            } else {
                $tpl_languages[] = $tpl_language;
            }
        }

        $missing_language_ids = array_diff(
            array_keys($db_languages),
            array_keys($fs_languages)
        );

        foreach ($missing_language_ids as $language_id) {
            $extension_repository->reassignUsersFromLanguage($language_id, $this->userService->getDefaultLanguage());
            $extension_repository->delete(ExtensionType::Language, $language_id);
        }

        $adminContent = $this->renderer->render(new LanguagesInstalledView(
            languages: $tpl_languages,
            isWebmaster: $this->accessControl->isWebmaster() ? 1 : 0,
            enableExtensionsInstall: $this->currentConfig->enableExtensionsInstall,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Languages'),
        );
    }
}
