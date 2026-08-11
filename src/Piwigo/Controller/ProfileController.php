<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\ProfilePageContext;
use Piwigo\Controller\Request\ProfileActionRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AdminContext;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Location\LocBeginProfile;
use Piwigo\Event\Location\LocEndProfile;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\Projection\DefaultUserInfo;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces profile.php -- lets the current user customize their own
 * gallery display settings. `save_profile_from_post()`/
 * `load_profile_in_template()` live in `Piwigo\Controller\
 * ProfileFormHandler` rather than as private methods here, because
 * `Controller\Admin\ConfigurationSubController`'s "default" (Guest
 * settings) tab also calls both. `ProfileFormHandler` is a
 * non-`ControllerInterface` helper class living directly in
 * `Piwigo\Controller` rather than in a dedicated `Piwigo\Profile`
 * namespace.
 *
 * check_pwg_token() and ProfileFormHandler::saveFromPost()'s own
 * redirect() both happen before any rendering starts.
 */
final readonly class ProfileController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private AdminContext $adminContext,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private EntityManagerInterface $entityManager,
        private ActivityService $activityService,
        private UserService $userService,
        private PasswordService $passwordService,
        private AuthService $authService,
        private HtmlService $htmlService,
        private MailService $mailService,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private Paths $paths,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->currentTemplate->get();

        $this->accessControl->checkStatus(AccessLevel::Classic);

        $profileAction = ProfileActionRequest::fromGlobals();

        if ($profileAction->requiresCsrfCheck) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlService, $this->redirectService);
        }

        // Load language if cookie is set from login/register/password pages.
        // This block
        // used to run much later in this method, *after*
        // assignVarFromHandle('PROFILE_CONTENT', 'profile_content')
        // below -- Smarty's assignVarFromHandle() renders the referenced
        // template immediately (not lazily deferred to the final page
        // render), so profile_content.tpl was always rendered with
        // whatever language was active BEFORE this switch, and the
        // Lang::load() call had no effect on anything the response actually
        // showed. Moved to run first, before $userdata/any template
        // rendering, so the whole response (including $userdata's own
        // 'language' field) consistently reflects the just-switched
        // language.
        $cookie_lang = $_COOKIE['lang'] ?? null;
        if ($cookie_lang !== null and (! is_string($cookie_lang) or $this->currentUser->get()->language->value !== $cookie_lang)) {
            if (! is_string($cookie_lang)) {
                $this->htmlService
                    ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($cookie_lang, LangService::getLanguages($this->paths))) {
                $this->htmlService
                    ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }

            $this->currentUser->updateLanguage(LangCode::from($cookie_lang));
            $this->userService->updateInfosForUser($this->currentUser->get()->id, [
                'language' => $cookie_lang,
            ]);
            $this->entityManager->clear();

            $this->lang->load('common.lang', '', [
                'language' => $cookie_lang,
            ]);
        }

        $userdata = $this->currentUser->get()
            ->toUserArray();

        $this->eventDispatcher->dispatchNotify(new LocBeginProfile());

        $fields = [
            'nb_image_page', 'expand',
            'show_nb_comments', 'show_nb_hits', 'recent_period', 'show_nb_hits',
        ];

        // Get the Guest custom settings -- UserService::getDefaultUserInfo()
        // already provides this exact row (process-cached, expand/
        // show_nb_comments/show_nb_hits already real bool), narrowed back
        // down to $fields so no extra column (activation_key included)
        // leaks into the DEFAULT_USER_VALUES template assignment below,
        // matching this method's own original raw-query column list.
        $default_user = $this->userService->getDefaultUserInfo();
        $default_user = $default_user instanceof DefaultUserInfo ? array_intersect_key($default_user->toArray(), array_flip($fields)) : [];

        // profile.tpl's inline JS (preferencesDefaultValues) interpolates
        // these bare/unquoted, relying on the *old* enum('true','false')
        // string rendering as the literal JS tokens true/false -- a real
        // bool would render as PHP's own `1`/`` instead, so render the
        // same JS-literal string explicitly, matching
        // ProfileFormHandler::loadIntoTemplate()'s existing convention for
        // the identical case. A separate array, not a mutation of
        // $default_user itself -- that one still feeds the $userdata
        // merge below as real bool.
        $default_user_for_template = $default_user;
        foreach (['expand', 'show_nb_comments', 'show_nb_hits'] as $k) {
            if (isset($default_user_for_template[$k])) {
                $default_user_for_template[$k] = SqlDialect::getBoolean($default_user_for_template[$k]) ? 'true' : 'false';
            }
        }
        // Reset to default (Guest) custom settings
        if ($profileAction->resetToDefault) {
            $userdata = array_merge($userdata, $default_user);
        }

        $profileFormHandler = new ProfileFormHandler($this->lang, $this->redirectService, $this->adminContext, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->entityManager, $this->activityService, $this->userService, $this->passwordService, $this->authService, $this->htmlService, $this->mailService, $this->currentConfig, $this->paths);

        $page_errors = $this->pageState->errors;
        $profileFormHandler->saveFromPost($userdata, $page_errors);
        $this->pageState->errors = array_values($page_errors);

        $this->pageState->setBodyId('theProfilePage');
        $template->setFilename('profile', 'profile.tpl');
        $template->setFilename('profile_content', 'profile_content.tpl');

        $profileFormHandler->loadIntoTemplate(
            $this->urlService->getRootUrl() . 'profile.php', // action
            $this->urlService->makeIndexUrl(), // for redirect
            $userdata
        );
        $template->assignVarFromHandle('PROFILE_CONTENT', 'profile_content');

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $title = $this->lang->t('Your Gallery Customization');

        // include menubar
        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('theProfilePage', $hide_menu_on, true)) {
            if (($themeconf['id'] ?? null) !== 'standard_pages') {
                new MenubarRenderer()
                    ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
            }
        }

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);

        // Get list of languages
        $language_options = [];
        foreach (LangService::getLanguages($this->paths) as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        // Get link to doc
        if (str_starts_with($this->currentUser->get()->language->value, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assignContext(new ProfilePageContext(
            defaultUserValues: $default_user_for_template,
            languageOptions: $language_options,
            languageSelection: $this->currentUser->get()
                ->language,
            helpLink: $help_link,
        ));

        $this->eventDispatcher->dispatchNotify(new LocEndProfile());
        $this->htmlService
            ->flushPageMessages();
        $template->parse('profile', false);
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
