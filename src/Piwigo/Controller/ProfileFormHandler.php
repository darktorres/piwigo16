<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;
use Piwigo\Users\UserService;

/**
 * P23 batch 8c: ported from `include/profile_functions.inc.php`'s 2
 * top-level functions (`save_profile_from_post()`/
 * `load_profile_in_template()`, itself extracted from `profile.php` in
 * P22). Both real callers are already-migrated `src/Piwigo/` classes
 * (`ProfileController`, `Controller\Admin\ConfigurationSubController`'s
 * "default" tab) -- unlike the free-function delegates elsewhere in this
 * batch, this is genuine `$_POST`/`$page`/`$template`-coupled business
 * logic with no existing typed home, so it gets its own class here
 * (`Piwigo\Controller`, matching `LegacyRenderCapture`'s own precedent of
 * a non-`ControllerInterface` helper class living directly in this
 * namespace) rather than a relocated free function.
 */
final class ProfileFormHandler
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    private static function activityService(Connection $conn): \Piwigo\Activity\ActivityService
    {
        return \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService();
    }

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    private static function passwordService(): PasswordService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::passwordService();
    }

    private static function authService(): AuthService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::authService();
    }

    // ------------------------------------------------------ update & customization
    /**
     * @param array<string, mixed> $userdata
     * @param array<int, string> $errors
     */
    public function saveFromPost(array $userdata, array &$errors): bool
    {
        $errors = [];

        $profileFormSubmitRequest = Request\ProfileFormSubmitRequest::fromGlobals();

        if (! $profileFormSubmitRequest->isValidateSubmitted) {
            return false;
        }

        // A local working copy of $_POST -- the special-user/not-admin-context
        // branches below used to unset()/overwrite several $_POST keys in
        // place so every later read in this same method saw the overridden
        // state; that stays entirely within this one method call, so it's
        // mutated here instead of the real superglobal.
        $post = $profileFormSubmitRequest->post;

        $conn = DbConnection::build();

        // $userdata['id'] is always the current session user's numeric id
        // (built in include/user.inc.php from \Piwigo\Config\CurrentConfig::guestId() or
        // $_SESSION['pwg_uid'], never a raw untyped value); narrow once here
        // for reuse below.
        $user_id = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;

        // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific DB
        // column names (see include/config_default.inc.php); always a
        // string=>string map at runtime (same invariant documented in
        // validate_mail_address(), functions_user.inc.php).
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $special_user = in_array($userdata['id'], [\Piwigo\Config\CurrentConfig::guestId(), \Piwigo\Config\CurrentConfig::defaultUserId()], true);
        if ($special_user) {
            unset(
                $post['username'],
                $post['mail_address'],
                $post['password'],
                $post['use_new_pwd'],
                $post['passwordConf'],
                $post['theme'],
                $post['language']
            );
            $post['theme'] = self::userService()->getDefaultTheme();
            $post['language'] = self::userService()->getDefaultLanguage();
        }

        if (! \Piwigo\Core\AdminContext::isActive()) {
            unset($post['username']);
        }

        if (\Piwigo\Config\CurrentConfig::allowUserCustomization() or \Piwigo\Core\AdminContext::isActive()) {
            $int_pattern = '/^\d+$/';
            // $_POST values are always strings or arrays -- never a real
            // PHP int/float/bool -- so only the null/string/array-emptiness
            // checks are reachable here.
            $nb_image_page = $post['nb_image_page'] ?? null;
            $nb_image_page_is_empty = $nb_image_page === null || $nb_image_page === '' || $nb_image_page === '0'
                || $nb_image_page === [];
            if ($nb_image_page_is_empty
                or (! is_scalar($nb_image_page))
                or (! (bool) preg_match($int_pattern, (string) $nb_image_page))) {
                $errors[] = Lang::t('The number of photos per page must be a not null scalar');
            }

            // periods must be integer values, they represents number of days
            $recent_period = $post['recent_period'] ?? null;
            if (! is_scalar($recent_period)
                or ! (bool) preg_match($int_pattern, (string) $recent_period)
                or $recent_period < 0) {
                $errors[] = Lang::t('Recent period must be a positive integer value');
            }

            if (! in_array($post['language'] ?? null, array_keys(\Piwigo\Lang\LangService::getLanguages()), true)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('Hacking attempt, incorrect language value');
            }

            if (! in_array($post['theme'] ?? null, array_keys(\Piwigo\Core\ThemeCatalog::getPwgThemes()), true)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($post['mail_address'])) {
            // if $_POST and $userdata have are same email
            // validate_mail_address allows, however, to check email
            $mail_address_input = is_string($post['mail_address']) ? $post['mail_address'] : null;
            $mail_error = self::userService()->validateMailAddress(\Piwigo\Common\ValueObject\UserId::tryFrom($user_id), $mail_address_input);
            if ($mail_error !== '' && $mail_error !== '0') {
                $errors[] = $mail_error;
            }
        }

        // $_POST values are always strings or arrays -- see
        // $nb_image_page_is_empty's own comment above.
        $new_pwd_present_raw = $post['use_new_pwd'] ?? null;
        $new_pwd_present = $new_pwd_present_raw !== null && $new_pwd_present_raw !== '' && $new_pwd_present_raw !== '0'
            && $new_pwd_present_raw !== [];
        if ($new_pwd_present) {
            // password must be the same as its confirmation
            $new_pwd_raw = $post['use_new_pwd'] ?? null;
            $pwd_conf_raw = $post['passwordConf'] ?? null;
            if (
                (is_string($new_pwd_raw) ? $new_pwd_raw : '')
                !== (is_string($pwd_conf_raw) ? $pwd_conf_raw : '')
            ) {
                $errors[] = Lang::t('The passwords do not match');
            }

            if (! \Piwigo\Core\AdminContext::isActive()) {// changing password requires old password
                $current_password = self::authService()->getPasswordHash($user_id, $user_fields['id'], $user_fields['username'], $user_fields['password']);

                // the password column allows NULL (external-authentication
                // accounts with no local password set); such an account can
                // never verify against a supplied old password
                $password_input = $post['password'] ?? null;
                if (! is_string($current_password)
                    or ! is_string($password_input)
                    or ! self::passwordService()->verify($password_input, $current_password)) {
                    $errors[] = Lang::t('Current password is wrong');
                }
            }
        }

        if (count($errors) === 0) {
            $activity_details_tables = [];

            if (isset($post['mail_address'])) {
                // update common user informations
                $fields = [$user_fields['email']];
                $mail_address = is_string($post['mail_address']) ? $post['mail_address'] : '';

                $data = [];
                $data[$user_fields['id']] = $userdata['id'];
                $data[$user_fields['email']] = $mail_address;

                // password is updated only if filled
                $new_pwd_for_update = $post['use_new_pwd'] ?? null;
                if (is_string($new_pwd_for_update) and $new_pwd_for_update !== '' and $new_pwd_for_update !== '0') {
                    $fields[] = $user_fields['password'];
                    $data[$user_fields['password']] = self::passwordService()->hash($new_pwd_for_update);

                    self::authService()->deactivateUserAuthKeys($user_id);
                }

                // username is updated only if allowed
                $username_for_update = $post['username'] ?? null;
                if (is_string($username_for_update) and $username_for_update !== '' and $username_for_update !== '0') {
                    $username = $username_for_update;
                    $usernameVo = \Piwigo\Common\ValueObject\Username::tryFrom($username);
                    if ($username !== $userdata['username'] and $usernameVo !== null and self::userService()->getUserId($usernameVo) !== null) {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('this login is already used'));
                        unset($post['redirect']);
                    } else {
                        $fields[] = $user_fields['username'];
                        $data[$user_fields['username']] = $username;

                        // send email to the user
                        if ($username !== $userdata['username']) {
                            $notification_language = is_string($userdata['language']) ? $userdata['language'] : self::userService()->getDefaultLanguage();
                            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                                ->switchLangTo($notification_language);

                            $keyargs_content = [
                                Lang::buildArgs('Hello', ''),
                                Lang::buildArgs('Your username has been successfully changed to : %s', $username),
                            ];

                            $gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();
                            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                                ->mail(
                                    $mail_address,
                                    [
                                        'subject' => '[' . $gallery_title . '] ' . Lang::t('Username modification'),
                                        'content' => Lang::args($keyargs_content),
                                        'content_format' => 'text/plain',
                                    ]
                                );

                            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                                ->switchLangBack();
                        }
                    }
                }

                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::users(),
                        [
                            'primary' => [$user_fields['id']],
                            'update' => $fields,
                        ],
                        [$data]
                    );

                if ($mail_address !== $userdata['email']) {
                    self::authService()->deactivatePasswordResetKey($user_id);
                }

                $activity_details_tables[] = 'users';
            }

            if (\Piwigo\Config\CurrentConfig::allowUserCustomization() or \Piwigo\Core\AdminContext::isActive()) {
                // update user "additional" informations (specific to Piwigo)
                $fields = [
                    'nb_image_page', 'language',
                    'expand', 'show_nb_hits', 'recent_period', 'theme',
                ];

                if (\Piwigo\Config\CurrentConfig::activateComments()) {
                    $fields[] = 'show_nb_comments';
                }

                $data = [];
                $data['user_id'] = $userdata['id'];

                // expand/show_nb_hits/show_nb_comments post as the literal
                // strings 'true'/'false' ({html_radios} in
                // profile_content.tpl uses $radio_options's own keys as
                // the submitted value) -- real tinyint columns now (User
                // domain Stage 1a), so the string form must become 1/0
                // before reaching massUpdate(); every other field in
                // $fields is untouched.
                $boolFields = ['expand', 'show_nb_hits', 'show_nb_comments'];
                foreach ($fields as $field) {
                    if (! isset($post[$field])) {
                        continue;
                    }

                    $value = $post[$field];
                    if (in_array($field, $boolFields, true) and is_string($value)) {
                        $value = SqlDialect::getBoolean($value) ? '1' : '0';
                    }

                    $data[$field] = $value;
                }
                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::userInfos(),
                        [
                            'primary' => ['user_id'],
                            'update' => $fields,
                        ],
                        [$data]
                    );
                \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

                $activity_details_tables[] = 'user_infos';
            }
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('save_profile_from_post', $userdata['id']);
            self::activityService($conn)->record('user', $user_id, 'edit', [
                'function' => __METHOD__,
                'tables' => implode(',', $activity_details_tables),
            ]);

            $redirect_target = $post['redirect'] ?? null;
            if (is_string($redirect_target) and $redirect_target !== '' and $redirect_target !== '0') {
                $this->redirectService->redirect($redirect_target);
            }
        }
        return true;
    }

    /**
     * Assign template variables, from arguments
     * Used to build profile edition pages
     *
     * @param string $url_action
     * @param string $url_redirect
     * @param array<string, mixed> $userdata
     */
    public function loadIntoTemplate($url_action, $url_redirect, array $userdata, ?string $template_prefixe = null): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->assign(
            'radio_options',
            [
                'true' => Lang::t('Yes'),
                'false' => Lang::t('No'),
            ]
        );

        $template->assign(
            [
                $template_prefixe . 'USERNAME' => stripslashes(is_string($userdata['username']) ? $userdata['username'] : ''),
                $template_prefixe . 'EMAIL' => @$userdata['email'],
                $template_prefixe . 'ALLOW_USER_CUSTOMIZATION' => \Piwigo\Config\CurrentConfig::allowUserCustomization(),
                $template_prefixe . 'ACTIVATE_COMMENTS' => \Piwigo\Config\CurrentConfig::activateComments(),
                $template_prefixe . 'NB_IMAGE_PAGE' => $userdata['nb_image_page'],
                $template_prefixe . 'RECENT_PERIOD' => $userdata['recent_period'],
                $template_prefixe . 'EXPAND' => (bool) $userdata['expand'] ? 'true' : 'false',
                $template_prefixe . 'NB_COMMENTS' => (bool) $userdata['show_nb_comments'] ? 'true' : 'false',
                $template_prefixe . 'NB_HITS' => (bool) $userdata['show_nb_hits'] ? 'true' : 'false',
                $template_prefixe . 'REDIRECT' => $url_redirect,
                $template_prefixe . 'F_ACTION' => $url_action,
            ]
        );

        $template->assign('template_selection', $userdata['theme']);
        $template->assign('template_options', \Piwigo\Core\ThemeCatalog::getPwgThemes());

        $profileFormSubmitRequest = Request\ProfileFormSubmitRequest::fromGlobals();

        $language_options = [];
        foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
            if ($profileFormSubmitRequest->isSubmitPresent or (is_string($userdata['language']) and $userdata['language'] === $language_code)) {
                $template->assign('language_selection', $language_code);
            }
            $language_options[$language_code] = $language_name;
        }

        $template->assign('language_options', $language_options);

        $special_user = in_array($userdata['id'], [\Piwigo\Config\CurrentConfig::guestId(), \Piwigo\Config\CurrentConfig::defaultUserId()], true);
        $template->assign('SPECIAL_USER', $special_user);
        $template->assign('IN_ADMIN', \Piwigo\Core\AdminContext::isActive());

        // api key expiration choice
        $conn = DbConnection::build();
        $sqlDialectExecutor = new \Piwigo\Db\SqlDialectExecutor($conn);
        $dbnow_str = $sqlDialectExecutor->fetchTomorrow();
        $template->assign('API_CURRENT_DATE', explode(' ', $dbnow_str)[0]);

        $display_duration = [];
        $has_custom = false;
        $api_key_duration = \Piwigo\Config\CurrentConfig::apiKeyDuration();
        $duration_days = [];
        foreach ($api_key_duration as $day) {
            if ($day === 'custom') {
                $has_custom = true;
                continue;
            }
            $duration_days[] = (int) $day;
        }

        $result = $sqlDialectExecutor->fetchFutureDatesFor($duration_days);
        foreach ($result as $day => $date) {
            $date_for_format = (is_string($date) || is_int($date)) ? $date : false;
            $display_duration[$day] = Lang::t('%d days', $day) . ' (' . \Piwigo\Core\DateHelper::formatDate($date_for_format, ['day', 'month', 'year']) . ')';
        }

        if ($has_custom) {
            $display_duration['custom'] = Lang::t('Custom date');
        }
        $template->assign('API_EXPIRATION', $display_duration);
        $template->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
        $template->assign('API_CAN_MANAGE', 'pwg_ui' === ($_SESSION['connected_with'] ?? null));

        $current_user_email = \Piwigo\Users\CurrentUser::get()->email;
        $email_notifications_infos = $current_user_email !== '' ?
          Lang::t('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $current_user_email)
          : Lang::t('You have no email address, so you will not be notified when your API key is about to expire.');
        $template->assign('API_EMAIL_INFOS', $email_notifications_infos);

        // allow plugins to add their own form data to content
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('load_profile_in_template', $userdata);

        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());
    }
}
