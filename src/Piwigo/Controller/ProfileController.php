<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\Dml;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Url\UrlService;

/**
 * Handles the user profile / preferences page (/profile).
 * Corresponds to the former profile.php entry-point (direct-access block).
 */
final class ProfileController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        PermissionService::get()->checkStatus(AccessLevel::Classic);

        if (!empty($_POST)) {
            check_pwg_token();
        }

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $userdata = $user;

        EventDispatcher::notify('loc_begin_profile');

        $default_user = ServiceLocator::get(UserRepository::class)
            ->getDefaultUserInfo(Config::defaultUserId());

        $tpl = TemplateRegistry::current();
        $tpl->assign('DEFAULT_USER_VALUES', $default_user);

        if (input_string('reset_to_default', null, $_POST) !== null) {
            $userdata = array_merge($userdata, $default_user ?? []);
        }

        $pgErrors = is_array($page['errors'] ?? null) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $page['errors']) : [];
        ServiceLocator::get(ProfileService::class)->saveProfileFromPost($userdata, $pgErrors);
        $page['errors'] = $pgErrors;

        $title = l10n('Your Gallery Customization');
        $page['body_id'] = 'theProfilePage';
        $tpl->setFilename('profile', 'profile.tpl');
        $tpl->setFilename('profile_content', 'profile_content.tpl');

        ServiceLocator::get(ProfileService::class)->loadProfileInTemplate(ServiceLocator::get(UrlGenerator::class)->profile(), UrlService::get()->makeIndexUrl(), $userdata);

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
                'opt_hits'      => !empty($userdata['show_nb_hits']),
            ],
            'preferencesDefaultValues' => [
                'nb_image_page' => $default_user['nb_image_page'] ?? null,
                'recent_period' => $default_user['recent_period'] ?? null,
                'opt_album'     => !empty($default_user['expand'] ?? null),
                'opt_comment'   => !empty($default_user['show_nb_comments'] ?? null),
                'opt_hits'      => !empty($default_user['show_nb_hits'] ?? null),
            ],
            'standardSaveSelector' => [],
            'selected_date'        => $tpl->getTemplateVars('API_SELECTED_EXPIRATION') ?? '',
            'no_time_elapsed'      => l10n('right now'),
            'str_handle_error'     => l10n('An error has occured'),
            'str_copy_key_secret'  => l10n('Secret copied. Keep it in a safe place.'),
            'str_copy_key_id'      => l10n('ID copied.'),
            'str_api_edited'       => l10n('API Key has been successfully edited.'),
            'str_api_revoked'      => l10n('API Key has been successfully revoked.'),
            'str_api_added'        => l10n('The api key has been successfully created.'),
            'str_revoke_key'       => l10n('Do you really want to revoke the "%s" API key?'),
            'str_cant_copy'        => l10n('Impossible to copy automatically. Please copy manually.'),
            'str_show_expired'     => l10n('Show expired keys'),
            'str_hide_expired'     => l10n('Hide expired keys'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromHandle('PROFILE_CONTENT', 'profile_content');

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theProfilePage', $hideMenuOn)) {
            if (($themeconfArr['id'] ?? '') !== 'standard_pages') {
                ServiceLocator::get(MenubarRenderer::class)->render();
            }
        }

        PageHeaderRenderer::render($title);

        $cookie_lang = input_string('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, get_languages())) {
                fatal_error('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            Dml::singleUpdate(Tables::userInfos(), ['language' => $cookie_lang], ['user_id' => $user['id']]);
            load_language('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach (get_languages() as $language_code => $language_name) {
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

        EventDispatcher::notify('loc_end_profile');
        flush_page_messages();
        $tpl->pparse('profile');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
