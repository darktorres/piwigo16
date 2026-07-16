<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Template\Template;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces register.php -- the user self-registration form + POST handler.
 * page_forbidden()/redirect() both happen before any rendering starts, so
 * the whole "process form" business logic stays outside the captured
 * closure -- same exit()-based-termination limitation as every other
 * controller this phase.
 */
final class RegisterController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $conf, $page;

        // $page['errors'] is always initialized to an array by
        // common.inc.php, but that isn't visible across the include()
        // boundary -- narrow it once here so every write below type-checks.
        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        if (! (bool) $conf['allow_user_registration']) {
            new HtmlService()
                ->pageForbidden('User registration closed');
        }

        trigger_notify('loc_begin_register');

        if (isset($_POST['submit'])) {
            $post_key = $_POST['key'] ?? null;
            if (! is_string($post_key)) {
                $post_key = '';
            }
            if (! (new \Piwigo\Auth\EphemeralKeyService())->verify($post_key)) {
                set_status_header(403);
                $page['errors']['register_page_error'] = l10n('Invalid/expired form key');
            }

            $post_password_raw = $_POST['password'] ?? null;
            $post_password_conf_raw = $_POST['password_conf'] ?? null;

            if ($post_password_raw === null || $post_password_raw === '' || $post_password_raw === '0') {
                $page['errors']['register_form_error'] = l10n('Password is missing. Please enter the password.');
            } elseif ($post_password_conf_raw === null || $post_password_conf_raw === '' || $post_password_conf_raw === '0') {
                $page['errors']['register_form_error'] = l10n('Password confirmation is missing. Please confirm the chosen password.');
            } elseif (
                (is_string($post_password_raw) ? $post_password_raw : '')
                !== (is_string($post_password_conf_raw) ? $post_password_conf_raw : '')
            ) {
                $page['errors']['register_form_error'] = l10n('The passwords do not match');
            }

            $post_login = is_string($_POST['login'] ?? null) ? $_POST['login'] : '';
            $post_password = is_string($post_password_raw) ? $post_password_raw : '';
            $post_mail_address = is_string($_POST['mail_address'] ?? null) ? $_POST['mail_address'] : null;

            // UserService::registerUser()'s $errors is a plain
            // list<string> of validation messages, a different shape than
            // $page['errors'], which register.tpl reads by the specific
            // keys 'register_page_error'/'register_form_error' set above.
            // Fold it into 'register_form_error' instead of overwriting
            // those keys.
            //
            // [SEC-31] registerUser() never puts a "this login is already
            // used" message in $errors -- a duplicate username comes back
            // as duplicateUsername: true with userId: null instead, and is
            // handled identically to a real success below (same redirect,
            // same flash message), so a requester probing for taken
            // usernames can't tell the two apart from the response. See
            // UserService::registerUser()'s own docblock for the full
            // rationale (this is also why an existing account gets a
            // "someone tried to register your username" email instead of
            // the requester ever seeing an error here).
            $registration_result = new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()), new MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
                ->registerUser(
                    $post_login,
                    $post_password,
                    $post_mail_address,
                    true,
                    isset($_POST['send_password_by_mail'])
                );
            $registration_errors = $registration_result['errors'];
            $new_user_id = $registration_result['userId'];

            // [SEC-57] Only a real new account is audit-logged -- a
            // duplicate username (userId: null) never reaches here, same
            // "don't reveal/don't act on a duplicate as if it were real"
            // discipline as the auto-login gate below. Actor is the new
            // user themselves (self-registration has no separate acting
            // admin); only the username is recorded, never the password.
            if ($new_user_id !== null) {
                new AuditService(new AuditRepository(DbConnection::build()))
                    ->record($new_user_id, 'create', 'user', $new_user_id, null, [
                        'username' => $post_login,
                    ]);
            }

            if ($registration_errors !== []) {
                $existing_form_error = $page['errors']['register_form_error'] ?? null;
                $form_error_messages = is_string($existing_form_error)
                    ? [$existing_form_error, ...$registration_errors]
                    : $registration_errors;
                $page['errors']['register_form_error'] = implode(' ', $form_error_messages);
            }

            if (count($page['errors']) === 0) {
                // email notification
                if (isset($_POST['send_password_by_mail']) and \Piwigo\Validation\InputValidator::checkEmailFormat($post_mail_address)) {
                    if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                        $_SESSION['page_infos'] = [];
                    }
                    $_SESSION['page_infos'][] = l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                }

                // [SEC-31] Only a real new account gets logged in -- a
                // duplicate username (userId: null) must never look up and
                // log into the *existing* account by name here, which
                // would be a full account-takeover, not just an
                // information leak. Both cases redirect identically.
                if ($new_user_id !== null) {
                    (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->logUser($new_user_id, false);
                }
                redirect(make_index_url());
            }
            $registration_post_key = (new \Piwigo\Auth\EphemeralKeyService())->generate(2);
        } else {
            $registration_post_key = (new \Piwigo\Auth\EphemeralKeyService())->generate(6);
        }

        $login_raw = $_POST['login'] ?? null;
        $login = is_string($login_raw) && $login_raw !== '' && $login_raw !== '0' ? htmlspecialchars(stripslashes($login_raw)) : '';

        $mail_raw = $_POST['mail_address'] ?? null;
        $email = is_string($mail_raw) && $mail_raw !== '' && $mail_raw !== '0' ? htmlspecialchars(stripslashes($mail_raw)) : '';

        $body = LegacyRenderCapture::capture(static function () use ($registration_post_key, $login, $email): void {
            /**
             * @var array<string, mixed> $conf
             * @var array<string, mixed> $page
             * @var Template $template
             * @var array<string, mixed> $user
             */
            global $conf, $page, $template, $user, $title;

            $title = l10n('Registration');
            $page['body_id'] = 'theRegisterPage';

            $template->set_filenames([
                'register' => 'register.tpl',
            ]);
            $template->assign([
                'U_HOME' => make_index_url(),
                'F_KEY' => $registration_post_key,
                'F_ACTION' => 'register.php',
                'F_LOGIN' => $login,
                'F_EMAIL' => $email,
                'obligatory_user_mail_address' => $conf['obligatory_user_mail_address'],
            ]);

            $themeconf = $template->get_template_vars('themeconf');
            $hide_menu_on = is_array($themeconf) ? ($themeconf['hide_menu_on'] ?? null) : null;
            if (! is_array($hide_menu_on) or ! in_array('theRegisterPage', $hide_menu_on, true)) {
                new MenubarRenderer()
                    ->render();
            }

            // Load language if cookie is set from login/register/password
            // pages
            $lang_cookie = $_COOKIE['lang'] ?? null;
            if ($lang_cookie !== null and (! is_string($lang_cookie) or $user['language'] !== $lang_cookie)) {
                if (! is_string($lang_cookie)) {
                    fatal_error('[Hacking attempt] the input parameter "lang" is not valid');
                }
                if (! array_key_exists($lang_cookie, \Piwigo\Lang\LangService::getLanguages())) {
                    fatal_error('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
                }

                $user['language'] = $lang_cookie;
                Lang::load('common.lang', '', [
                    'language' => $user['language'],
                ]);
            }

            $language_options = [];
            foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
                $language_options[$language_code] = $language_name;
            }

            $template->assign([
                'language_options' => $language_options,
                'current_language' => $user['language'],
            ]);

            $user_language_for_help = $user['language'] ?? '';
            $user_language_for_help = is_string($user_language_for_help) ? $user_language_for_help : '';
            if (str_starts_with($user_language_for_help, 'fr')) {
                $help_link = 'https://upstream.example.invalid/help/fr/';
            } else {
                $help_link = 'https://upstream.example.invalid/help/';
            }

            $template->assign('HELP_LINK', $help_link);

            include PHPWG_ROOT_PATH . 'include/page_header.php';
            trigger_notify('loc_end_register');
            new HtmlService()
                ->flushPageMessages();
            $template->parse('register');
            include PHPWG_ROOT_PATH . 'include/page_tail.php';
        });

        return ResponseFactory::html($body);
    }
}
