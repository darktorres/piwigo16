<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Latte\Runtime\Html;
use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\Details\ProfileEditDetails;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Event\User\LoadProfileInTemplate;
use Piwigo\Event\User\SaveProfileFromPost;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageService;
use Piwigo\Mail\MailService;
use Piwigo\Session\ConnectionType;
use Piwigo\Session\Session;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Theme\ThemeService;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ProfileService
{
    public function __construct(
        private AuthService $authService,
        private DateService $dateService,
        private LangService $langService,
        private MailService $mailService,
        private UserRepository $userRepository,
        private UserService $userService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private RedirectResponder $redirectResponder,
        private LanguageService $languageService,
        private ThemeService $themeService,
        private Session $session,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param array<string,mixed> $userdata
     * @param string[] $errors
     */
    public function saveProfileFromPost(array $userdata, array &$errors): bool
    {
        $errors = [];

        if (!isset($_POST['validate'])) {
            return false;
        }

        $special_user = in_array($userdata['id'], [Config::guestId(), Config::defaultUserId()]);
        if ($special_user) {
            unset($_POST['username'], $_POST['mail_address'], $_POST['password'], $_POST['use_new_pwd'], $_POST['passwordConf'], $_POST['theme'], $_POST['language']);
            $_POST['theme']    = $this->userService->getDefaultTheme();
            $_POST['language'] = $this->userService->getDefaultLanguage();
        }

        $inAdmin = RequestContextRegistry::current() === RequestContext::Admin;

        if (!$inAdmin) {
            unset($_POST['username']);
        }

        if (Config::allowUserCustomization() or $inAdmin) {
            $int_pattern = '/^\d+$/';
            $nbImagePageRaw = $_POST['nb_image_page'] ?? null;
            if ((!isset($_POST['nb_image_page']) || $nbImagePageRaw === null || $nbImagePageRaw === '') or (!preg_match($int_pattern, is_string($nbImagePageRaw) ? $nbImagePageRaw : ''))) {
                $errors[] = Lang::t('The number of photos per page must be a not null scalar');
            }
            $rawRecentPeriod = $_POST['recent_period'] ?? null;
            $recentPeriodPost = is_string($rawRecentPeriod) ? $rawRecentPeriod : null;
            if (!preg_match($int_pattern, $recentPeriodPost ?? '')
                or (is_numeric($recentPeriodPost) ? (int) $recentPeriodPost : 0) < 0
            ) {
                $errors[] = Lang::t('Recent period must be a positive integer value');
            }
            if (!in_array($_POST['language'] ?? null, array_keys($this->languageService->getActiveLanguages()))) {
                die('Hacking attempt, incorrect language value');
            }
            if (!in_array($_POST['theme'] ?? null, array_keys($this->themeService->getActiveThemes()))) {
                die('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($_POST['mail_address'])) {
            $mail_error = $this->authService->validateMailAddress(is_int($userdata['id'] ?? null) ? $userdata['id'] : null, is_string($_POST['mail_address']) ? $_POST['mail_address'] : null);
            if ($mail_error !== null && $mail_error !== '') {
                $errors[] = $mail_error;
            }
        }

        if (isset($_POST['use_new_pwd']) && $_POST['use_new_pwd'] !== '') {
            if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
                $errors[] = Lang::t('The passwords do not match');
            }
            if (!$inAdmin) {
                $current_password = $this->userRepository->findPasswordById(
                    Config::userFields()->password,
                    Config::userFields()->id,
                    Tables::users(),
                    is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0
                );
                if (!password_verify(is_string($rawProfilePwd = $_POST['password'] ?? null) ? $rawProfilePwd : '', is_string($current_password) ? $current_password : '')) {
                    $errors[] = Lang::t('Current password is wrong');
                }
            }
        }

        if (count($errors) == 0) {
            $activity_details_tables = [];

            if (isset($_POST['mail_address'])) {
                $fields = [Config::userFields()->email];
                $data   = [];
                $data[Config::userFields()->id]    = $userdata['id'];
                $data[Config::userFields()->email] = $_POST['mail_address'];

                if (isset($_POST['use_new_pwd']) && $_POST['use_new_pwd'] !== '') {
                    $fields[]                                  = Config::userFields()->password;
                    $data[Config::userFields()->password]    = password_hash(is_string($rawNewPwd = $_POST['use_new_pwd']) ? $rawNewPwd : '', PASSWORD_BCRYPT);
                    $this->authService->deactivateUserAuthKeys(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
                }

                if (isset($_POST['username']) && $_POST['username'] !== '') {
                    if ($_POST['username'] != $userdata['username'] and $this->userService->getUserid(is_string($_POST['username']) ? $_POST['username'] : '') !== false) {
                        PageState::current()->addError(Lang::t('this login is already used'));
                        unset($_POST['redirect']);
                    } else {
                        $fields[]                                   = Config::userFields()->username;
                        $data[Config::userFields()->username]     = $_POST['username'];
                        if ($_POST['username'] != $userdata['username']) {
                            $this->mailService->switchLangTo(is_string($userdata['language'] ?? null) ? $userdata['language'] : '');
                            $keyargs_content = [
                                $this->langService->getL10nArgs('Hello', ''),
                                $this->langService->getL10nArgs('Your username has been successfully changed to : %s', $_POST['username']),
                            ];
                            $this->mailService->pwgMail(
                                is_string($_POST['mail_address']) ? $_POST['mail_address'] : '',
                                ['subject' => '[' . Config::galleryTitle() . '] ' . Lang::t('Username modification'), 'content' => $this->langService->l10nArgs($keyargs_content), 'content_format' => 'text/plain']
                            );
                            $this->mailService->switchLangBack();
                        }
                    }
                }

                $idField = Config::userFields()->id;
                $set     = [];
                foreach ($fields as $field) {
                    $set[$field] = $data[$field] ?? null;
                }
                $userIdRaw = $data[$idField] ?? null;
                $userIdInt = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;
                $this->userRepository->updateUserById(Tables::users(), $idField, $userIdInt, $set);

                if ($_POST['mail_address'] != $userdata['email']) {
                    $this->authService->deactivatePasswordResetKey(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
                }
                $activity_details_tables[] = 'users';
            }

            if (Config::allowUserCustomization() or $inAdmin) {
                $fields = ['nb_image_page', 'language', 'expand', 'show_nb_hits', 'recent_period', 'theme'];
                if (Config::activateComments()) {
                    $fields[] = 'show_nb_comments';
                }
                $data            = [];
                $data['user_id'] = $userdata['id'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        $data[$field] = $_POST[$field];
                    }
                }
                $set = [];
                foreach ($fields as $field) {
                    if (array_key_exists($field, $data)) {
                        $set[$field] = $data[$field];
                    }
                }
                if ($set !== []) {
                    $userIdInt = is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : 0;
                    $this->userRepository->updateUserInfosById($userIdInt, $set);
                }
                $activity_details_tables[] = 'user_infos';
            }

            $userId = is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0;
            $this->dispatcher->dispatch(new SaveProfileFromPost($userId));
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, ActivityAction::Edit, new ProfileEditDetails('saveProfileFromPost', implode(',', $activity_details_tables))));

            if (isset($_POST['redirect']) && $_POST['redirect'] !== '') {
                $this->redirectResponder->redirect(is_string($_POST['redirect']) ? $_POST['redirect'] : UrlService::getRootUrl());
            }
        }
        return true;
    }

    /**
     * Assign template variables for profile edition pages.
     *
     * @param array<string,mixed> $userdata
     */
    public function loadProfileInTemplate(string $url_action, string $url_redirect, array $userdata, ?string $template_prefixe = null): void
    {
        $tpl  = TemplateRegistry::current();
        $user = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];

        $tplPrefix = $template_prefixe ?? '';
        $tpl->assign('radio_options', ['true' => Lang::t('Yes'), 'false' => Lang::t('No')]);
        $tpl->assign([
            $tplPrefix . 'USERNAME'               => stripslashes(is_scalar($userdata['username'] ?? null) ? (string) $userdata['username'] : ''),
            $tplPrefix . 'EMAIL'                  => $userdata['email'] ?? null,
            $tplPrefix . 'ALLOW_USER_CUSTOMIZATION' => Config::allowUserCustomization(),
            $tplPrefix . 'ACTIVATE_COMMENTS'      => Config::activateComments(),
            $tplPrefix . 'NB_IMAGE_PAGE'          => $userdata['nb_image_page'],
            $tplPrefix . 'RECENT_PERIOD'          => $userdata['recent_period'],
            $tplPrefix . 'EXPAND'                 => $userdata['expand'] ? 'true' : 'false',
            $tplPrefix . 'NB_COMMENTS'            => $userdata['show_nb_comments'] ? 'true' : 'false',
            $tplPrefix . 'NB_HITS'                => $userdata['show_nb_hits'] ? 'true' : 'false',
            $tplPrefix . 'REDIRECT'               => $url_redirect,
            $tplPrefix . 'F_ACTION'               => $url_action,
        ]);

        $tpl->assign('template_selection', $userdata['theme']);
        $tpl->assign('template_options', $this->themeService->getActiveThemes());

        $language_options = [];
        foreach ($this->languageService->getActiveLanguages() as $language_code => $language_name) {
            if (isset($_POST['submit']) or $userdata['language'] == $language_code) {
                $tpl->assign('language_selection', $language_code);
            }
            $language_options[$language_code] = $language_name;
        }
        $tpl->assign('language_options', $language_options);

        $special_user = in_array($userdata['id'], [Config::guestId(), Config::defaultUserId()]);
        $tpl->assign('SPECIAL_USER', $special_user);
        $tpl->assign('IN_ADMIN', RequestContextRegistry::current() === RequestContext::Admin);

        $dbnow    = new \DateTimeImmutable('+1 day')->format('Y-m-d H:i:s');
        $tpl->assign('API_CURRENT_DATE', explode(' ', $dbnow)[0]);

        $durationDays     = [];
        $display_duration = [];
        $has_custom       = false;
        foreach (Config::apiKeyDuration() as $day) {
            if ('custom' === $day) {
                $has_custom = true;
                continue;
            }
            $durationDays[] = is_numeric($day) ? (int) $day : 0;
        }

        $result = $this->userRepository->findApiKeyExpirationDatesFor($durationDays);
        foreach ($result as $day => $date) {
            $display_duration[$day] = Lang::t('%d days', $day) . ' (' . $this->dateService->formatDate($date, ['day', 'month', 'year']) . ')';
        }
        if ($has_custom) {
            $display_duration['custom'] = Lang::t('Custom date');
        }
        $tpl->assign('API_EXPIRATION', $display_duration);
        $tpl->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
        $tpl->assign('API_CAN_MANAGE', ConnectionType::PwgUi->value === $this->session->connectedWith);

        $userEmail = is_scalar($user['email'] ?? null) ? (string) $user['email'] : '';
        $email_notifications_infos = $userEmail
            ? Lang::t('The email <em>%s</em> will be used to notify you when your API key is about to expire.', htmlspecialchars($userEmail))
            : Lang::t('You have no email address, so you will not be notified when your API key is about to expire.');
        $tpl->assign('API_EMAIL_INFOS', new Html($email_notifications_infos));

        $this->dispatcher->dispatch(new LoadProfileInTemplate($userdata));
        $tpl->assign('PWG_TOKEN', $this->csrfService->getToken());
    }
}
