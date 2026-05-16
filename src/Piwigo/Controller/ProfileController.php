<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocBeginProfile;
use Piwigo\Event\Location\LocEndProfile;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the user profile / preferences page (/profile).
 * Corresponds to the former profile.php entry-point (direct-access block).
 */
final readonly class ProfileController implements ControllerInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private ProfileService $profileService,
        private UrlGenerator $urlGenerator,
        private UserRepository $userRepository,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private LangService $langService,
        private UrlService $urlService,
        private LanguageService $languageService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        $this->permissionService->checkStatus(AccessLevel::Classic);

        if (!empty($_POST)) {
            $this->csrfService->check();
        }

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $userdata = $user;

        $this->dispatcher->dispatch(new LocBeginProfile());

        $default_user = $this->userRepository
            ->getDefaultUserInfo(Config::defaultUserId());

        $tpl = TemplateRegistry::current();
        $tpl->assign('DEFAULT_USER_VALUES', $default_user);

        if (StringUtil::inputString('reset_to_default', null, $_POST) !== null) {
            $userdata = array_merge($userdata, $default_user ?? []);
        }

        $pgErrors = [];
        foreach (PageState::current()->errors as $v) {
            $pgErrors[] = is_string($v) ? $v : (string) $v;
        }
        $this->profileService->saveProfileFromPost($userdata, $pgErrors);
        PageState::current()->errors = array_values($pgErrors);

        $title = Lang::t('Your Gallery Customization');
        PageState::current()->bodyId = 'theProfilePage';
        $this->profileService->loadProfileInTemplate($this->urlGenerator->profile(), $this->urlService->makeIndexUrl(), $userdata);

        $userdata_id = is_scalar($userdata['id'] ?? null) ? $userdata['id'] : null;
        $special_user = in_array($userdata_id, [Config::guestId(), Config::defaultUserId()]);
        $tpl->assign('page_data_json', json_encode([
            'canUpdatePreferences' => Config::allowUserCustomization(),
            'canUpdatePassword'    => !$special_user,
            'can_manage_api'       => 'pwg_ui' === ($_SESSION['connected_with'] ?? null),
            'user' => [
                'username'      => stripslashes(is_scalar($userdata['username'] ?? null) ? (string) $userdata['username'] : ''),
                'email'         => is_scalar($userdata['email'] ?? null) ? (string) $userdata['email'] : '',
                'nb_image_page' => is_scalar($userdata['nb_image_page'] ?? null) ? (string) $userdata['nb_image_page'] : '',
                'theme'         => is_scalar($userdata['theme'] ?? null) ? (string) $userdata['theme'] : '',
                'language'      => is_scalar($userdata['language'] ?? null) ? (string) $userdata['language'] : '',
                'recent_period' => is_scalar($userdata['recent_period'] ?? null) ? (string) $userdata['recent_period'] : '',
                'opt_album'     => !empty($userdata['expand']),
                'opt_comment'   => !empty($userdata['show_nb_comments']),
                'opt_hits'      => isset($userdata['show_nb_hits']) && $userdata['show_nb_hits'] !== '' && $userdata['show_nb_hits'] !== false && $userdata['show_nb_hits'] !== 0,
            ],
            'preferencesDefaultValues' => [
                'nb_image_page' => $default_user['nb_image_page'] ?? null,
                'recent_period' => $default_user['recent_period'] ?? null,
                'opt_album'     => isset($default_user['expand']) && $default_user['expand'] !== '' && $default_user['expand'] !== false && $default_user['expand'] !== 0,
                'opt_comment'   => isset($default_user['show_nb_comments']) && $default_user['show_nb_comments'] !== '' && $default_user['show_nb_comments'] !== false && $default_user['show_nb_comments'] !== 0,
                'opt_hits'      => isset($default_user['show_nb_hits']) && $default_user['show_nb_hits'] !== '' && $default_user['show_nb_hits'] !== false && $default_user['show_nb_hits'] !== 0,
            ],
            'standardSaveSelector' => [],
            'selected_date'        => $tpl->getTemplateVars('API_SELECTED_EXPIRATION') ?? '',
            'no_time_elapsed'      => Lang::t('right now'),
            'str_handle_error'     => Lang::t('An error has occured'),
            'str_copy_key_secret'  => Lang::t('Secret copied. Keep it in a safe place.'),
            'str_copy_key_id'      => Lang::t('ID copied.'),
            'str_api_edited'       => Lang::t('API Key has been successfully edited.'),
            'str_api_revoked'      => Lang::t('API Key has been successfully revoked.'),
            'str_api_added'        => Lang::t('The api key has been successfully created.'),
            'str_revoke_key'       => Lang::t('Do you really want to revoke the "%s" API key?'),
            'str_cant_copy'        => Lang::t('Impossible to copy automatically. Please copy manually.'),
            'str_show_expired'     => Lang::t('Show expired keys'),
            'str_hide_expired'     => Lang::t('Hide expired keys'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromTemplate('PROFILE_CONTENT', 'profile_content.latte');

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theProfilePage', $hideMenuOn)) {
            if (($themeconfArr['id'] ?? '') !== 'standard_pages') {
                $this->menubarRenderer->render();
            }
        }

        PageHeaderRenderer::render($title);

        $cookie_lang = StringUtil::inputString('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, $this->languageService->getActiveLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            Dml::singleUpdate(Tables::userInfos(), ['language' => $cookie_lang], ['user_id' => $user['id']]);
            $this->langService->loadLanguage('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach ($this->languageService->getActiveLanguages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign(['language_options' => $language_options, 'language_selection' => $userLang]);
        $tpl->assign('std_pages_data_json', json_encode([
            'selected_language' => $language_options[$userLang] ?? '',
            'url_logo_light'    => UrlService::getRootUrl() . 'themes/standard_pages/images/piwigo_logo.svg',
            'url_logo_dark'     => UrlService::getRootUrl() . 'themes/standard_pages/images/piwigo_logo_dark.svg',
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $help_link = str_starts_with($userLang, 'fr')
            ? 'https://doc-fr.piwigo.org/les-utilisateurs/se-connecter-a-piwigo'
            : 'https://doc.piwigo.org/managing-users/log-in-to-piwigo';
        $tpl->assign('HELP_LINK', $help_link);

        $this->dispatcher->dispatch(new LocEndProfile());
        $this->htmlService->flushPageMessages();
        $tpl->pparse('profile.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
