<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Db\Tables;
use Piwigo\Url\UrlService;

final class ProfileService
{
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
            $_POST['theme']    = UserService::get()->getDefaultTheme();
            $_POST['language'] = UserService::get()->getDefaultLanguage();
        }

        if (!defined('IN_ADMIN')) {
            unset($_POST['username']);
        }

        if (Config::allowUserCustomization() or defined('IN_ADMIN')) {
            $int_pattern = '/^\d+$/';
            if (empty($_POST['nb_image_page']) or (!preg_match($int_pattern, is_scalar($_POST['nb_image_page']) ? (string) $_POST['nb_image_page'] : ''))) {
                $errors[] = l10n('The number of photos per page must be a not null scalar');
            }
            if (!preg_match($int_pattern, is_scalar($_POST['recent_period'] ?? null) ? (string) $_POST['recent_period'] : '')
                or (is_numeric($_POST['recent_period'] ?? null) ? $_POST['recent_period'] : 0) < 0
            ) {
                $errors[] = l10n('Recent period must be a positive integer value');
            }
            if (!in_array($_POST['language'] ?? null, array_keys(get_languages()))) {
                die('Hacking attempt, incorrect language value');
            }
            if (!in_array($_POST['theme'] ?? null, array_keys(get_pwg_themes()))) {
                die('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($_POST['mail_address'])) {
            $mail_error = AuthService::get()->validateMailAddress(is_int($userdata['id'] ?? null) ? $userdata['id'] : null, is_string($_POST['mail_address']) ? $_POST['mail_address'] : null);
            if (!empty($mail_error)) {
                $errors[] = $mail_error;
            }
        }

        if (!empty($_POST['use_new_pwd'])) {
            if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
                $errors[] = l10n('The passwords do not match');
            }
            if (!defined('IN_ADMIN')) {
                $current_password = ServiceLocator::get(UserRepository::class)->findPasswordById(
                    Config::userFields()['password'],
                    Config::userFields()['id'],
                    Tables::users(),
                    is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0
                );
                if (!password_verify(is_scalar($_POST['password']) ? (string) $_POST['password'] : '', is_string($current_password) ? $current_password : '')) {
                    $errors[] = l10n('Current password is wrong');
                }
            }
        }

        if (count($errors) == 0) {
            $activity_details_tables = [];

            if (isset($_POST['mail_address'])) {
                $fields = [Config::userFields()['email']];
                $data   = [];
                $data[Config::userFields()['id']]    = $userdata['id'];
                $data[Config::userFields()['email']] = $_POST['mail_address'];

                if (!empty($_POST['use_new_pwd'])) {
                    $fields[]                                  = Config::userFields()['password'];
                    $data[Config::userFields()['password']]    = password_hash(is_scalar($_POST['use_new_pwd']) ? (string) $_POST['use_new_pwd'] : '', PASSWORD_BCRYPT);
                    ServiceLocator::get(AuthService::class)->deactivateUserAuthKeys(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
                }

                if (!empty($_POST['username'])) {
                    if ($_POST['username'] != $userdata['username'] and UserService::get()->getUserid(is_string($_POST['username']) ? $_POST['username'] : '')) {
                        PageState::current()->addError(l10n('this login is already used'));
                        unset($_POST['redirect']);
                    } else {
                        $fields[]                                   = Config::userFields()['username'];
                        $data[Config::userFields()['username']]     = $_POST['username'];
                        if ($_POST['username'] != $userdata['username']) {
                            switch_lang_to(is_string($userdata['language'] ?? null) ? $userdata['language'] : '');
                            $keyargs_content = [
                                get_l10n_args('Hello', ''),
                                get_l10n_args('Your username has been successfully changed to : %s', $_POST['username']),
                            ];
                            pwg_mail(
                                is_string($_POST['mail_address']) ? $_POST['mail_address'] : '',
                                ['subject' => '[' . Config::galleryTitle() . '] ' . l10n('Username modification'), 'content' => l10n_args($keyargs_content), 'content_format' => 'text/plain']
                            );
                            switch_lang_back();
                        }
                    }
                }

                Dml::massUpdates(Tables::users(), ['primary' => [Config::userFields()['id']], 'update' => $fields], [$data]);

                if ($_POST['mail_address'] != $userdata['email']) {
                    ServiceLocator::get(AuthService::class)->deactivatePasswordResetKey(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
                }
                $activity_details_tables[] = 'users';
            }

            if (Config::allowUserCustomization() or defined('IN_ADMIN')) {
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
                Dml::massUpdates(Tables::userInfos(), ['primary' => ['user_id'], 'update' => $fields], [$data]);
                $activity_details_tables[] = 'user_infos';
            }

            $userId = is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0;
            EventDispatcher::notify('save_profile_from_post', $userId);
            pwg_activity('user', $userId, 'edit', ['function' => 'saveProfileFromPost', 'tables' => implode(',', $activity_details_tables)]);

            if (!empty($_POST['redirect'])) {
                redirect(is_string($_POST['redirect']) ? $_POST['redirect'] : UrlService::getRootUrl());
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
        $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];

        $tpl->assign('radio_options', ['true' => l10n('Yes'), 'false' => l10n('No')]);
        $tpl->assign([
            $template_prefixe . 'USERNAME'               => stripslashes(is_scalar($userdata['username'] ?? null) ? (string) $userdata['username'] : ''),
            $template_prefixe . 'EMAIL'                  => $userdata['email'] ?? null,
            $template_prefixe . 'ALLOW_USER_CUSTOMIZATION' => Config::allowUserCustomization(),
            $template_prefixe . 'ACTIVATE_COMMENTS'      => Config::activateComments(),
            $template_prefixe . 'NB_IMAGE_PAGE'          => $userdata['nb_image_page'],
            $template_prefixe . 'RECENT_PERIOD'          => $userdata['recent_period'],
            $template_prefixe . 'EXPAND'                 => $userdata['expand'] ? 'true' : 'false',
            $template_prefixe . 'NB_COMMENTS'            => $userdata['show_nb_comments'] ? 'true' : 'false',
            $template_prefixe . 'NB_HITS'                => $userdata['show_nb_hits'] ? 'true' : 'false',
            $template_prefixe . 'REDIRECT'               => $url_redirect,
            $template_prefixe . 'F_ACTION'               => $url_action,
        ]);

        $tpl->assign('template_selection', $userdata['theme']);
        $tpl->assign('template_options', get_pwg_themes());

        $language_options = [];
        foreach (get_languages() as $language_code => $language_name) {
            if (isset($_POST['submit']) or $userdata['language'] == $language_code) {
                $tpl->assign('language_selection', $language_code);
            }
            $language_options[$language_code] = $language_name;
        }
        $tpl->assign('language_options', $language_options);

        $special_user = in_array($userdata['id'], [Config::guestId(), Config::defaultUserId()]);
        $tpl->assign('SPECIAL_USER', $special_user);
        $tpl->assign('IN_ADMIN', defined('IN_ADMIN'));

        $dbnow    = new \DateTimeImmutable('+1 day')->format('Y-m-d H:i:s');
        $tpl->assign('API_CURRENT_DATE', explode(' ', $dbnow)[0]);

        $duration         = [];
        $display_duration = [];
        $has_custom       = false;
        foreach (Config::apiKeyDuration() as $day) {
            if ('custom' === $day) {
                $has_custom = true;
                continue;
            }
            $dayStr       = is_scalar($day) ? (string) $day : '0';
            $duration[]   = 'ADDDATE(NOW(), INTERVAL ' . $dayStr . ' DAY) as `' . $dayStr . '`';
        }

        $query  = 'SELECT ' . implode(', ', $duration) . ';';
        $result = DbConnection::get()->executeQuery($query)->fetchAllAssociative()[0];
        foreach ($result as $day => $date) {
            $display_duration[$day] = l10n('%d days', $day) . ' (' . format_date(is_scalar($date) ? (string) $date : null, ['day', 'month', 'year']) . ')';
        }
        if ($has_custom) {
            $display_duration['custom'] = l10n('Custom date');
        }
        $tpl->assign('API_EXPIRATION', $display_duration);
        $tpl->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
        $tpl->assign('API_CAN_MANAGE', 'pwg_ui' === ($_SESSION['connected_with'] ?? null));

        $userEmail = is_scalar($user['email'] ?? null) ? (string) $user['email'] : '';
        $email_notifications_infos = $userEmail
            ? l10n('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $userEmail)
            : l10n('You have no email address, so you will not be notified when your API key is about to expire.');
        $tpl->assign('API_EMAIL_INFOS', $email_notifications_infos);

        EventDispatcher::notify('load_profile_in_template', $userdata);
        $tpl->assign('PWG_TOKEN', get_pwg_token());
    }
}
