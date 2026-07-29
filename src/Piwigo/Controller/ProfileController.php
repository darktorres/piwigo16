<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Users\UserService;
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
 * redirect() both happen before any rendering starts.
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class ProfileController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Classic);

        $profileAction = Request\ProfileActionRequest::fromGlobals();

        if ($profileAction->requiresCsrfCheck) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        // Load language if cookie is set from login/register/password pages.
        // Real bug, found while adding coverage for this branch: this block
        // used to run much later in this method, *after*
        // assign_var_from_handle('PROFILE_CONTENT', 'profile_content')
        // below -- Smarty's assign_var_from_handle() renders the referenced
        // template immediately (not lazily deferred to the final page
        // render), so profile_content.tpl was always rendered with
        // whatever language was active BEFORE this switch, and the
        // Lang::load() call had no effect on anything the response actually
        // showed. Moved to run first, before $userdata/any template
        // rendering, so the whole response (including $userdata's own
        // 'language' field) consistently reflects the just-switched
        // language.
        $cookie_lang = $_COOKIE['lang'] ?? null;
        if ($cookie_lang !== null and (! is_string($cookie_lang) or \Piwigo\Users\CurrentUser::get()->language !== $cookie_lang)) {
            if (! is_string($cookie_lang)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($cookie_lang, \Piwigo\Lang\LangService::getLanguages())) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }

            \Piwigo\Users\CurrentUser::updateLanguage($cookie_lang);
            new BatchWriter(DbConnection::build())->singleUpdate(
                Tables::userInfos(),
                [
                    'language' => $cookie_lang,
                ],
                [
                    'user_id' => \Piwigo\Users\CurrentUser::get()->id->value,
                ]
            );
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

            Lang::load('common.lang', '', [
                'language' => $cookie_lang,
            ]);
        }

        $userdata = \Piwigo\Users\CurrentUser::get()->toUserArray();

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_profile');

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
        $default_user = self::userService()->getDefaultUserInfo();
        $default_user = is_array($default_user) ? array_intersect_key($default_user, array_flip($fields)) : [];

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
        $template->assign('DEFAULT_USER_VALUES', $default_user_for_template);

        // Reset to default (Guest) custom settings
        if ($profileAction->resetToDefault) {
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
            $this->urlService->getRootUrl() . 'profile.php', // action
            $this->urlService->makeIndexUrl(), // for redirect
            $userdata
        );
        $template->assign_var_from_handle('PROFILE_CONTENT', 'profile_content');

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $title = Lang::t('Your Gallery Customization');

        // include menubar
        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('theProfilePage', $hide_menu_on, true)) {
            if (($themeconf['id'] ?? null) !== 'standard_pages') {
                new MenubarRenderer()
                    ->render($urlService);
            }
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);

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
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        $template->parse('profile', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
