<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Request\ProfileFormSubmitRequest;
use Piwigo\Core\AdminContext;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ThemeCatalog;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\SqlDialectExecutor;
use Piwigo\Event\User\LoadProfileInTemplate;
use Piwigo\Event\User\SaveProfileFromPost;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Holds `$_POST`/`$page`/`$template`-coupled profile business logic, used
 * by `ProfileController` and `Controller\Admin\ConfigurationSubController`'s
 * "default" tab. Lives directly in the `Piwigo\Controller` namespace as a
 * non-`ControllerInterface` helper class, the same pattern
 * `LegacyRenderCapture` uses.
 */
final class ProfileFormHandler
{
    public function __construct(
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly AdminContext $adminContext,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly PasswordService $passwordService,
        private readonly AuthService $authService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly MailService $mailService,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    // ------------------------------------------------------ update & customization
    /**
     * @param array<string, mixed> $userdata
     * @param array<int, string> $errors
     */
    public function saveFromPost(array $userdata, array &$errors): bool
    {
        $errors = [];

        $profileFormSubmitRequest = ProfileFormSubmitRequest::fromGlobals();

        if (! $profileFormSubmitRequest->isValidateSubmitted) {
            return false;
        }

        // A local working copy of $_POST -- the special-user/not-admin-context
        // branches below unset()/overwrite several $_POST keys in place so
        // every later read in this same method sees the overridden state;
        // that stays entirely within this one method call, so it's mutated
        // here instead of the real superglobal.
        $post = $profileFormSubmitRequest->post;

        $conn = DbConnection::build();

        // $userdata['id'] is always the current session user's numeric id
        // (built in include/user.inc.php from \Piwigo\Config\CurrentConfig::guestId() or
        // $_SESSION['pwg_uid'], never a raw untyped value); narrow once here
        // for reuse below.
        $user_id = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;

        $special_user = in_array($userdata['id'], [$this->currentConfig->guestId(), $this->currentConfig->defaultUserId()], true);
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
            $post['theme'] = $this->userService->getDefaultTheme();
            $post['language'] = $this->userService->getDefaultLanguage();
        }

        if (! $this->adminContext->isActive()) {
            unset($post['username']);
        }

        if ($this->currentConfig->allowUserCustomization() or $this->adminContext->isActive()) {
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
                $errors[] = $this->lang->t('The number of photos per page must be a not null scalar');
            }

            // periods must be integer values, they represents number of days
            $recent_period = $post['recent_period'] ?? null;
            if (! is_scalar($recent_period)
                or ! (bool) preg_match($int_pattern, (string) $recent_period)
                or $recent_period < 0) {
                $errors[] = $this->lang->t('Recent period must be a positive integer value');
            }

            if (! in_array($post['language'] ?? null, array_keys(LangService::getLanguages($this->paths)), true)) {
                $this->htmlRenderer
                    ->fatalError('Hacking attempt, incorrect language value');
            }

            if (! in_array($post['theme'] ?? null, array_keys(ThemeCatalog::getPwgThemes($this->eventDispatcher, $this->paths, $this->currentConfig, $this->lang)), true)) {
                $this->htmlRenderer
                    ->fatalError('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($post['mail_address'])) {
            // if $_POST and $userdata have are same email
            // validate_mail_address allows, however, to check email
            $mail_address_input = is_string($post['mail_address']) ? $post['mail_address'] : null;
            $mail_error = $this->userService->validateMailAddress(UserId::tryFrom($user_id), $mail_address_input);
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
                $errors[] = $this->lang->t('The passwords do not match');
            }

            if (! $this->adminContext->isActive()) {// changing password requires old password
                $current_password = $this->authService->getPasswordHash(UserId::from($user_id));

                // the password column allows NULL (external-authentication
                // accounts with no local password set); such an account can
                // never verify against a supplied old password
                $password_input = $post['password'] ?? null;
                if (! is_string($current_password)
                    or ! is_string($password_input)
                    or ! $this->passwordService->verify($password_input, $current_password)) {
                    $errors[] = $this->lang->t('Current password is wrong');
                }
            }
        }

        if (count($errors) === 0) {
            $activity_details_tables = [];

            if (isset($post['mail_address'])) {
                // update common user informations
                $mail_address = is_string($post['mail_address']) ? $post['mail_address'] : '';
                $username_update = null;
                $password_update = null;

                // password is updated only if filled
                $new_pwd_for_update = $post['use_new_pwd'] ?? null;
                if (is_string($new_pwd_for_update) and $new_pwd_for_update !== '' and $new_pwd_for_update !== '0') {
                    $password_update = $this->passwordService->hash($new_pwd_for_update);

                    $this->authService->deactivateUserAuthKeys($user_id);
                }

                // username is updated only if allowed
                $username_for_update = $post['username'] ?? null;
                if (is_string($username_for_update) and $username_for_update !== '' and $username_for_update !== '0') {
                    $username = $username_for_update;
                    $usernameVo = Username::tryFrom($username);
                    if ($usernameVo === null) {
                        $this->pageState->addError($this->lang->t('invalid login format'));
                        unset($post['redirect']);
                    } elseif ($username !== $userdata['username'] and $this->userService->getUserId($usernameVo) !== null) {
                        $this->pageState->addError($this->lang->t('this login is already used'));
                        unset($post['redirect']);
                    } else {
                        $username_update = $usernameVo;

                        // send email to the user
                        if ($username !== $userdata['username']) {
                            $notification_language = is_string($userdata['language']) ? $userdata['language'] : $this->userService->getDefaultLanguage();
                            $this->mailService
                                ->switchLangTo($notification_language);

                            $keyargs_content = [
                                $this->lang->buildArgs('Hello', ''),
                                $this->lang->buildArgs('Your username has been successfully changed to : %s', $username),
                            ];

                            $gallery_title = $this->currentConfig->galleryTitle();
                            $this->mailService
                                ->mail(
                                    $mail_address,
                                    [
                                        'subject' => '[' . $gallery_title . '] ' . $this->lang->t('Username modification'),
                                        'content' => $this->lang->args($keyargs_content),
                                        'content_format' => 'text/plain',
                                    ]
                                );

                            $this->mailService
                                ->switchLangBack();
                        }
                    }
                }

                $this->userService->updateAccountFields(UserId::from($user_id), $username_update, $password_update, Email::tryFrom($mail_address));

                if ($mail_address !== $userdata['email']) {
                    $this->authService->deactivatePasswordResetKey($user_id);
                }

                $activity_details_tables[] = 'users';
            }

            if ($this->currentConfig->allowUserCustomization() or $this->adminContext->isActive()) {
                // update user "additional" informations (specific to Piwigo)
                $fields = [
                    'nb_image_page', 'language',
                    'expand', 'show_nb_hits', 'recent_period', 'theme',
                ];

                if ($this->currentConfig->activateComments()) {
                    $fields[] = 'show_nb_comments';
                }

                $data = [];
                $data['user_id'] = $userdata['id'];

                // expand/show_nb_hits/show_nb_comments post as the literal
                // strings 'true'/'false' ({html_radios} in
                // profile_content.tpl uses $radio_options's own keys as
                // the submitted value) -- these are tinyint columns, so
                // the string form must become 1/0 before reaching
                // massUpdate(); every other field in $fields is untouched.
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
                $infosUpdates = $data;
                unset($infosUpdates['user_id']);
                $this->userService->updateInfosForUser(UserId::from($user_id), $infosUpdates);
                $this->entityManager->clear();

                $activity_details_tables[] = 'user_infos';
            }
            $this->eventDispatcher->dispatchNotify(new SaveProfileFromPost(UserId::from($user_id)));
            $this->activityService->record('user', $user_id, 'edit', [
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
        $template = $this->currentTemplate->get();

        $template->assign(
            'radio_options',
            [
                'true' => $this->lang->t('Yes'),
                'false' => $this->lang->t('No'),
            ]
        );

        $template->assign(
            [
                $template_prefixe . 'USERNAME' => stripslashes(is_string($userdata['username']) ? $userdata['username'] : ''),
                $template_prefixe . 'EMAIL' => @$userdata['email'],
                $template_prefixe . 'ALLOW_USER_CUSTOMIZATION' => $this->currentConfig->allowUserCustomization(),
                $template_prefixe . 'ACTIVATE_COMMENTS' => $this->currentConfig->activateComments(),
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
        $template->assign('template_options', ThemeCatalog::getPwgThemes($this->eventDispatcher, $this->paths, $this->currentConfig, $this->lang));

        $profileFormSubmitRequest = ProfileFormSubmitRequest::fromGlobals();

        $language_options = [];
        foreach (LangService::getLanguages($this->paths) as $language_code => $language_name) {
            if ($profileFormSubmitRequest->isSubmitPresent or (is_string($userdata['language']) and $userdata['language'] === $language_code)) {
                $template->assign('language_selection', $language_code);
            }
            $language_options[$language_code] = $language_name;
        }

        $template->assign('language_options', $language_options);

        $special_user = in_array($userdata['id'], [$this->currentConfig->guestId(), $this->currentConfig->defaultUserId()], true);
        $template->assign('SPECIAL_USER', $special_user);
        $template->assign('IN_ADMIN', $this->adminContext->isActive());

        // api key expiration choice
        $conn = DbConnection::build();
        $sqlDialectExecutor = new SqlDialectExecutor($conn);
        $dbnow_str = $sqlDialectExecutor->fetchTomorrow();
        $template->assign('API_CURRENT_DATE', explode(' ', $dbnow_str)[0]);

        $display_duration = [];
        $has_custom = false;
        $api_key_duration = $this->currentConfig->apiKeyDuration();
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
            $display_duration[$day] = $this->lang->t('%d days', $day) . ' (' . DateHelper::formatDate($date_for_format, ['day', 'month', 'year']) . ')';
        }

        if ($has_custom) {
            $display_duration['custom'] = $this->lang->t('Custom date');
        }
        $template->assign('API_EXPIRATION', $display_duration);
        $template->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
        $template->assign('API_CAN_MANAGE', 'pwg_ui' === ($_SESSION['connected_with'] ?? null));

        $current_user_email = $this->currentUser->get()
            ->email;
        $email_notifications_infos = $current_user_email !== '' ?
          $this->lang->t('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $current_user_email)
          : $this->lang->t('You have no email address, so you will not be notified when your API key is about to expire.');
        $template->assign('API_EMAIL_INFOS', $email_notifications_infos);

        // allow plugins to add their own form data to content
        $this->eventDispatcher->dispatchNotify(new LoadProfileInTemplate($userdata));

        $template->assign('PWG_TOKEN', new CsrfService($this->currentConfig)->getToken());
    }
}
