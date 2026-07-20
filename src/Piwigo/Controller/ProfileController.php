<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces profile.php -- lets the current user customize their own gallery
 * display settings. The legacy file's own 2 top-level functions
 * (save_profile_from_post()/load_profile_in_template()) were ported to a
 * real `Piwigo\Controller\ProfileFormHandler` class (P23 batch 8c) rather
 * than becoming private methods here -- unlike every other page-owned free
 * function this phase, a project-wide grep found a real external caller:
 * `Controller\Admin\ConfigurationSubController`'s "default" (Guest
 * settings) tab also calls both. `LegacyRenderCapture`'s own precedent
 * (a non-`ControllerInterface` helper class living directly in
 * `Piwigo\Controller`) is why the new class lives here rather than a new
 * `Piwigo\Profile` namespace.
 *
 * check_pwg_token() and ProfileFormHandler::saveFromPost()'s own
 * redirect() both happen before any rendering starts, so all of the
 * "process form" business logic stays outside the captured closure --
 * same exit()-based-termination limitation as every other controller
 * this phase.
 */
final class ProfileController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed>
         */
        global $user;
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Classic);

        if ($_POST !== []) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);
        }

        $userdata = $user;

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_profile');

        $fields = [
            'nb_image_page', 'expand',
            'show_nb_comments', 'show_nb_hits', 'recent_period', 'show_nb_hits',
        ];

        // Get the Guest custom settings
        // \Piwigo\Config\Config::defaultUserId() is always an int (see
        // include/config_default.inc.php: derived from the int guest_id).
        $default_user_id = is_numeric(\Piwigo\Config\Config::defaultUserId()) ? (int) \Piwigo\Config\Config::defaultUserId() : 0;
        $query = '
SELECT ' . implode(',', $fields) . '
  FROM ' . Tables::userInfos() . '
  WHERE user_id = ' . $default_user_id . '
;';
        $conn = DbConnection::build();
        $default_user = $conn->fetchAssociative($query);
        // The guest user_infos row can plausibly be missing (deleted directly in
        // DB, broken migration, ...); fall back to an empty array rather than
        // trusting a no-op assert() (zend.assertions=-1 in this environment --
        // see getuserdata() in functions_user.inc.php for the same "fetch may
        // fail" invariant handled with a real guard instead of assert()).
        $default_user = is_array($default_user) ? $default_user : [];
        $template->assign('DEFAULT_USER_VALUES', $default_user);

        // Reset to default (Guest) custom settings
        if (isset($_POST['reset_to_default'])) {
            $userdata = array_merge($userdata, $default_user);
        }

        $profileFormHandler = new ProfileFormHandler($this->redirectService);

        $page_errors = \Piwigo\Core\PageState::current()->errors;
        $profileFormHandler->saveFromPost($userdata, $page_errors);
        \Piwigo\Core\PageState::current()->errors = array_values($page_errors);

        \Piwigo\Core\PageState::current()->setBodyId('theProfilePage');
        $template->set_filename('profile', 'profile.tpl');
        $template->set_filename('profile_content', 'profile_content.tpl');

        $profileFormHandler->loadIntoTemplate(
            get_root_url() . 'profile.php', // action
            make_index_url(), // for redirect
            $userdata
        );
        $template->assign_var_from_handle('PROFILE_CONTENT', 'profile_content');

        $body = LegacyRenderCapture::capture(static function (): void {
            // $title is set and read entirely within this closure (passed
            // straight into PageHeaderRenderer::render() below) -- no
            // other file reads $GLOBALS['title']. Plain local, not global.
            $template = \Piwigo\Template\CurrentTemplate::get();

            $title = l10n('Your Gallery Customization');

            // include menubar
            $themeconf = $template->get_template_vars('themeconf');
            $themeconf = is_array($themeconf) ? $themeconf : [];
            $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
            if (! is_array($hide_menu_on) or ! in_array('theProfilePage', $hide_menu_on, true)) {
                if (($themeconf['id'] ?? null) !== 'standard_pages') {
                    new MenubarRenderer()
                        ->render();
                }
            }

            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);

            // Load language if cookie is set from login/register/password pages
            $cookie_lang = $_COOKIE['lang'] ?? null;
            if ($cookie_lang !== null and (! is_string($cookie_lang) or \Piwigo\Users\CurrentUser::get()->language !== $cookie_lang)) {
                if (! is_string($cookie_lang)) {
                    new HtmlService()
                        ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
                }
                if (! array_key_exists($cookie_lang, \Piwigo\Lang\LangService::getLanguages())) {
                    new HtmlService()
                        ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
                }

                \Piwigo\Users\CurrentUser::updateLanguage($cookie_lang);
                new BatchWriter(DbConnection::build())->singleUpdate(
                    Tables::userInfos(),
                    [
                        'language' => $cookie_lang,
                    ],
                    [
                        'user_id' => \Piwigo\Users\CurrentUser::get()->id,
                    ]
                );

                Lang::load('common.lang', '', [
                    'language' => $cookie_lang,
                ]);
            }

            // Get list of languages
            $language_options = [];
            foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
                $language_options[$language_code] = $language_name;
            }

            $template->assign([
                'language_options' => $language_options,
                'language_selection' => \Piwigo\Users\CurrentUser::get()->language,
            ]);

            // Get link to doc
            if (str_starts_with(\Piwigo\Users\CurrentUser::get()->language, 'fr')) {
                $help_link = 'https://upstream.example.invalid/help/fr/';
            } else {
                $help_link = 'https://upstream.example.invalid/help/';
            }

            $template->assign('HELP_LINK', $help_link);

            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_profile');
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('profile');
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}
