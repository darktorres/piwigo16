<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
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
            if (!in_array($_POST['language'] ?? null, array_keys(Util::get()->getLanguages()))) {
                die('Hacking attempt, incorrect language value');
            }
            if (!in_array($_POST['theme'] ?? null, array_keys(ServiceLocator::get(Util::class)->getPwgThemes()))) {
                die('Hacking attempt, incorrect theme value');
            }
        }

        if (isset($_POST['mail_address'])) {
            $mail_error = AuthService::get()->validateMailAddress(is_int($userdata['id'] ?? null) ? $userdata['id'] : null, is_string($_POST['mail_address']) ? $_POST['mail_address'] : null);
            if ($mail_error !== null && $mail_error !== '') {
                $errors[] = $mail_error;
            }
        }

        if (isset($_POST['use_new_pwd']) && $_POST['use_new_pwd'] !== '') {
            if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
                $errors[] = Lang::t('The passwords do not match');
            }
            if (!defined('IN_ADMIN')) {
                $current_password = ServiceLocator::get(UserRepository::class)->findPasswordById(
                    Config::userFields()['password'],
                    Config::userFields()['id'],
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
                $fields = [Config::userFields()['email']];
                $data   = [];
                $data[Config::userFields()['id']]    = $userdata['id'];
                $data[Config::userFields()['email']] = $_POST['mail_address'];

                if (isset($_POST['use_new_pwd']) && $_POST['use_new_pwd'] !== '') {
                    $fields[]                                  = Config::userFields()['password'];
                    $data[Config::userFields()['password']]    = password_hash(is_string($rawNewPwd = $_POST['use_new_pwd']) ? $rawNewPwd : '', PASSWORD_BCRYPT);
                    ServiceLocator::get(AuthService::class)->deactivateUserAuthKeys(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
                }

                if (isset($_POST['username']) && $_POST['username'] !== '') {
                    if ($_POST['username'] != $userdata['username'] and UserService::get()->getUserid(is_string($_POST['username']) ? $_POST['username'] : '') !== false) {
                        PageState::current()->addError(Lang::t('this login is already used'));
                        unset($_POST['redirect']);
                    } else {
                        $fields[]                                   = Config::userFields()['username'];
                        $data[Config::userFields()['username']]     = $_POST['username'];
                        if ($_POST['username'] != $userdata['username']) {
                            ServiceLocator::get(MailService::class)->switchLangTo(is_string($userdata['language'] ?? null) ? $userdata['language'] : '');
                            $keyargs_content = [
                                LangService::get()->getL10nArgs('Hello', ''),
                                LangService::get()->getL10nArgs('Your username has been successfully changed to : %s', $_POST['username']),
                            ];
                            ServiceLocator::get(MailService::class)->pwgMail(
                                is_string($_POST['mail_address']) ? $_POST['mail_address'] : '',
                                ['subject' => '[' . Config::galleryTitle() . '] ' . Lang::t('Username modification'), 'content' => LangService::get()->l10nArgs($keyargs_content), 'content_format' => 'text/plain']
                            );
                            ServiceLocator::get(MailService::class)->switchLangBack();
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
            ServiceLocator::get(Util::class)->pwgActivity('user', $userId, 'edit', ['function' => 'saveProfileFromPost', 'tables' => implode(',', $activity_details_tables)]);

            if (isset($_POST['redirect']) && $_POST['redirect'] !== '') {
                Util::get()->redirect(is_string($_POST['redirect']) ? $_POST['redirect'] : UrlService::getRootUrl());
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
        $tpl->assign('template_options', ServiceLocator::get(Util::class)->getPwgThemes());

        $language_options = [];
        foreach (Util::get()->getLanguages() as $language_code => $language_name) {
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
            $display_duration[$day] = Lang::t('%d days', $day) . ' (' . ServiceLocator::get(DateService::class)->formatDate(is_scalar($date) ? (string) $date : null, ['day', 'month', 'year']) . ')';
        }
        if ($has_custom) {
            $display_duration['custom'] = Lang::t('Custom date');
        }
        $tpl->assign('API_EXPIRATION', $display_duration);
        $tpl->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
        $tpl->assign('API_CAN_MANAGE', 'pwg_ui' === ($_SESSION['connected_with'] ?? null));

        $userEmail = is_scalar($user['email'] ?? null) ? (string) $user['email'] : '';
        $email_notifications_infos = $userEmail
            ? Lang::t('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $userEmail)
            : Lang::t('You have no email address, so you will not be notified when your API key is about to expire.');
        $tpl->assign('API_EMAIL_INFOS', $email_notifications_infos);

        EventDispatcher::notify('load_profile_in_template', $userdata);
        $tpl->assign('PWG_TOKEN', ServiceLocator::get(Util::class)->getPwgToken());
    }
}
