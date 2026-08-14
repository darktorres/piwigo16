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
use Piwigo\Admin\Projection\LanguagesInstalledPageContext;
use Piwigo\Admin\Request\LanguagesInstalledActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
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
use Piwigo\Template\CurrentTemplate;
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
        private CurrentTemplate $currentTemplate,
        private ActivityService $activityService,
        private UserService $userService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private CurrentUser $currentUser,
        private Paths $paths,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * $pageSlug is an explicit param: the one real caller
     * (LanguagesSubController) knows its own fixed page slug statically
     * -- it's the only class registered for the 'languages' slug in
     * config/admin_pages.php.
     */
    public function render(string $pageSlug): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $extension_repository = new ExtensionRepository($this->entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->paths, $this->currentUser, $this->eventDispatcher, $this->pluginRegistry, $this->themeRegistry);

        $fs_languages = $extension_scanner->scan(ExtensionType::Language, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig);
        $db_languages = $extension_repository->findAll(ExtensionType::Language);

        // --------------------------------------------------perform requested actions
        $languagesAction = LanguagesInstalledActionRequest::fromGlobals('/^(' . join('|', array_keys($fs_languages)) . ')$/', $this->inputValidator);

        if ($languagesAction->action !== null and $languagesAction->languageId !== null and $this->accessControl->isWebmaster()) {
            new CsrfService($this->currentConfig)
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
            $language['u_action'] = $this->urlService->addUrlParams($base_url, [
                'language' => $language_id,
                'pwg_token' => new CsrfService($this->currentConfig)
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

        $missing_language_ids = array_diff(
            array_keys($db_languages),
            array_keys($fs_languages)
        );

        foreach ($missing_language_ids as $language_id) {
            $extension_repository->reassignUsersFromLanguage($language_id, $this->userService->getDefaultLanguage());
            $extension_repository->delete(ExtensionType::Language, $language_id);
        }

        $template->assignContext(new LanguagesInstalledPageContext(
            languages: $tpl_languages,
            isWebmaster: $this->accessControl->isWebmaster(),
            adminPageTitle: $this->lang->t('Languages'),
            enableExtensionsInstall: $this->currentConfig->enableExtensionsInstall,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'languages_installed.latte');
    }
}
