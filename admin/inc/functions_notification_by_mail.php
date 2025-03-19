<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

/* nbm_global_var */

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_mail;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Random\RandomException;

$env_nbm =
          [
            'start_time' => functions::get_moment(),
              'sendmail_timeout' => (intval(ini_get('max_execution_time')) * $conf['nbm_max_treatment_timeout_percent']),
              'is_sendmail_timeout' => false,
        ];

if (! isset($env_nbm['sendmail_timeout']) or
    ! is_numeric($env_nbm['sendmail_timeout']) or
    $env_nbm['sendmail_timeout'] <= 0
) {
    $env_nbm['sendmail_timeout'] = $conf['nbm_treatment_timeout_default'];
}

class functions_notification_by_mail
{
    /**
     * Search an available check_key
     *
     * It's a copy of function find_available_feed_id
     *
     * @return string nbm identifier
     * @throws RandomException
     */
    public static function find_available_check_key(): string
    {
        while (true) {
            $key = functions_session::generate_key(16);
            $query = <<<SQL
                SELECT COUNT(*)
                FROM user_mail_notification
                WHERE check_key = '{$key}';
                SQL;

            list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($count == 0) {
                return $key;
            }
        }
    }

    /**
     * Check sendmail timeout state
     *
     * @return true, if it's timeout
     */
    public static function check_sendmail_timeout(): bool
    {
        global $env_nbm;

        $env_nbm['is_sendmail_timeout'] = ((functions::get_moment() - $env_nbm['start_time']) > $env_nbm['sendmail_timeout']);

        return $env_nbm['is_sendmail_timeout'];
    }

    /**
     * Add quote to all elements of check_key_list
     *
     * @return array quoted check key list
     */
    public static function quote_check_key_list(
        array $check_key_list = []
    ): array {
        return array_map(function (string $s): string { return '\'' . $s . '\''; }, $check_key_list);
    }

    /**
     * Execute all main queries to get list of user
     *
     * Type are the type of list 'subscribe', 'send'
     *
     * @return array of users
     */
    public static function get_user_notifications(
        string $action,
        array $check_key_list = [],
        bool|string $enabled_filter_value = ''
    ): array {
        global $conf;

        $data_users = [];

        if (in_array($action, ['subscribe', 'send'])) {
            $quoted_check_key_list = self::quote_check_key_list($check_key_list);

            if (count($quoted_check_key_list) != 0) {
                $query_and_check_key = ' AND check_key IN (' . implode(', ', $quoted_check_key_list) . ') ';
            } else {
                $query_and_check_key = '';
            }

            $query = <<<SQL
                SELECT n.user_id, n.check_key, u.{$conf['user_fields']['username']} AS username, u.{$conf['user_fields']['email']} AS mail_address,
                    n.enabled, n.last_send, ui.status
                FROM user_mail_notification AS n
                JOIN users AS u ON n.user_id = u.{$conf['user_fields']['id']}
                JOIN user_infos AS ui ON ui.user_id = n.user_id
                WHERE 1 = 1

                SQL;

            if ($action == 'send') {
                // No mail empty and all users enabled
                $query .= <<<SQL
                    AND n.enabled = 'true'
                    AND u.{$conf['user_fields']['email']} IS NOT NULL

                    SQL;
            }

            $query .= $query_and_check_key;

            if (isset($enabled_filter_value) and
                $enabled_filter_value != ''
            ) {
                $filter_value = functions_mysqli::boolean_to_string($enabled_filter_value);
                $query .= <<<SQL
                    AND n.enabled = '{$filter_value}'

                    SQL;
            }

            if ($action == 'send') {
                $query .= <<<SQL
                    ORDER BY last_send, username

                    SQL;
            } else {
                $query .= <<<SQL
                    ORDER BY username

                    SQL;
            }

            $query = trim($query) . ';';
            $result = functions_mysqli::pwg_query($query);

            if (! empty($result)) {
                while ($nbm_user = functions_mysqli::pwg_db_fetch_assoc($result)) {
                    $data_users[] = $nbm_user;
                }
            }
        }

        return $data_users;
    }

    /**
     * Begin of use nbm environment
     * Prepare and save current environment and initialize data in order to send mail
     */
    public static function begin_users_env_nbm(
        bool $is_to_send_mail = false
    ): void {
        global $user, $lang, $lang_info, $conf, $env_nbm;

        // Save $user, $lang_info and $lang arrays (inc/user.php has been executed)
        $env_nbm['save_user'] = $user;
        // Save current language to stack, necessary because $user change during NBM
        functions_mail::switch_lang_to($user['language']);

        $env_nbm['is_to_send_mail'] = $is_to_send_mail;

        if ($is_to_send_mail) {
            // Init mail configuration
            $env_nbm['email_format'] = functions_mail::get_str_email_format($conf['nbm_send_html_mail']);
            $env_nbm['send_as_name'] = ((isset($conf['nbm_send_mail_as']) and ! empty($conf['nbm_send_mail_as'])) ? $conf['nbm_send_mail_as'] : functions_mail::get_mail_sender_name());
            $env_nbm['send_as_mail_address'] = functions::get_webmaster_mail_address();
            $env_nbm['send_as_mail_formated'] = functions_mail::format_email($env_nbm['send_as_name'], $env_nbm['send_as_mail_address']);
            // Init mail counter
            $env_nbm['error_on_mail_count'] = 0;
            $env_nbm['sent_mail_count'] = 0;
            // Save sendmail message info and error in the original language
            $env_nbm['msg_info'] = functions::l10n('Mail sent to %s [%s].');
            $env_nbm['msg_error'] = functions::l10n('Error when sending email to %s [%s].');
        }
    }

    /**
     * End of use nbm environment
     * Restore environment
     */
    public static function end_users_env_nbm(): void
    {
        global $user, $lang, $lang_info, $env_nbm;

        // Restore $user, $lang_info and $lang arrays (inc/user.php has been executed)
        $user = $env_nbm['save_user'];
        // Restore current language to stack, necessary because $user change during NBM
        functions_mail::switch_lang_back();

        if ($env_nbm['is_to_send_mail']) {
            unset($env_nbm['email_format']);
            unset($env_nbm['send_as_name']);
            unset($env_nbm['send_as_mail_address']);
            unset($env_nbm['send_as_mail_formated']);
            // Don t unset counter
            //unset($env_nbm['error_on_mail_count']);
            //unset($env_nbm['sent_mail_count']);
            unset($env_nbm['msg_info']);
            unset($env_nbm['msg_error']);
        }

        unset($env_nbm['save_user']);
        unset($env_nbm['is_to_send_mail']);
    }

    /**
     * Set user on nbm environment
     */
    public static function set_user_on_env_nbm(
        array &$nbm_user,
        bool $is_action_send
    ): void {
        global $user, $lang, $lang_info, $env_nbm;

        $user = functions_user::build_user($nbm_user['user_id'], true);

        functions_mail::switch_lang_to($user['language']);

        if ($is_action_send) {
            $env_nbm['mail_template'] = functions_mail::get_mail_template($env_nbm['email_format']);
            $env_nbm['mail_template']->set_filename('notification_by_mail', 'notification_by_mail.tpl');
        }
    }

    /**
     * Unset user on nbm environment
     */
    public static function unset_user_on_env_nbm(): void
    {
        global $env_nbm;

        functions_mail::switch_lang_back();
        unset($env_nbm['mail_template']);
    }

    /**
     * Inc Counter success
     */
    public static function inc_mail_sent_success(
        array $nbm_user
    ): void {
        global $page, $env_nbm;

        ++$env_nbm['sent_mail_count'];
        $page['infos'][] = sprintf($env_nbm['msg_info'], stripslashes($nbm_user['username']), $nbm_user['mail_address']);
    }

    /**
     * Inc Counter failed
     */
    public static function inc_mail_sent_failed(
        array $nbm_user
    ): void {
        global $page, $env_nbm;

        ++$env_nbm['error_on_mail_count'];
        $page['errors'][] = sprintf($env_nbm['msg_error'], stripslashes($nbm_user['username']), $nbm_user['mail_address']);
    }

    /**
     * Display Counter Info
     */
    public static function display_counter_info(): void
    {
        global $page, $env_nbm;

        if ($env_nbm['error_on_mail_count'] != 0) {
            $page['errors'][] = functions::l10n_dec(
                '%d mail was not sent.',
                '%d mails were not sent.',
                $env_nbm['error_on_mail_count']
            );

            if ($env_nbm['sent_mail_count'] != 0) {
                $page['infos'][] = functions::l10n_dec(
                    '%d mail was sent.',
                    '%d mails were sent.',
                    $env_nbm['sent_mail_count']
                );
            }
        } else {
            if ($env_nbm['sent_mail_count'] == 0) {
                $page['infos'][] = functions::l10n('No mail to send.');
            } else {
                $page['infos'][] = functions::l10n_dec(
                    '%d mail was sent.',
                    '%d mails were sent.',
                    $env_nbm['sent_mail_count']
                );
            }
        }
    }

    public static function assign_vars_nbm_mail_content(
        array $nbm_user
    ): void {
        global $env_nbm;

        functions_url::set_make_full_url();

        $env_nbm['mail_template']->assign(
            [
                'USERNAME' => stripslashes($nbm_user['username']),

                'SEND_AS_NAME' => $env_nbm['send_as_name'],

                'UNSUBSCRIBE_LINK' => functions_url::add_url_params(functions_url::get_gallery_home_url() . '/nbm.php', [
                    'unsubscribe' => $nbm_user['check_key'],
                ]),
                'SUBSCRIBE_LINK' => functions_url::add_url_params(functions_url::get_gallery_home_url() . '/nbm.php', [
                    'subscribe' => $nbm_user['check_key'],
                ]),
                'CONTACT_EMAIL' => $env_nbm['send_as_mail_address'],
            ]
        );

        functions_url::unset_make_full_url();
    }

    /**
     * Subscribe or unsubscribe notification by mail
     *
     * is_subscribe define if action=subscribe or unsubscribe
     * check_key list where action will be done
     *
     * @return array check_key list treated
     * @throws \Exception
     */
    public static function do_subscribe_unsubscribe_notification_by_mail(
        bool $is_admin_request,
        bool $is_subscribe = false,
        array $check_key_list = []
    ): array {
        global $conf, $page, $env_nbm, $conf;

        functions_url::set_make_full_url();

        $check_key_treated = [];
        $updated_data_count = 0;
        $error_on_updated_data_count = 0;

        if ($is_subscribe) {
            $msg_info = functions::l10n('User %s [%s] was added to the subscription list.');
            $msg_error = functions::l10n('User %s [%s] was not added to the subscription list.');
        } else {
            $msg_info = functions::l10n('User %s [%s] was removed from the subscription list.');
            $msg_error = functions::l10n('User %s [%s] was not removed from the subscription list.');
        }

        if (count($check_key_list) != 0) {
            $updates = [];
            $enabled_value = functions_mysqli::boolean_to_string($is_subscribe);
            $data_users = self::get_user_notifications('subscribe', $check_key_list, ! $is_subscribe);

            // Prepare message after change language
            $msg_break_timeout = functions::l10n('Time to send mail is limited. Others mails are skipped.');

            // Begin nbm users environment
            self::begin_users_env_nbm(true);

            foreach ($data_users as $nbm_user) {
                if (self::check_sendmail_timeout()) {
                    // Stop fill list on 'send', if the quota is override
                    $page['errors'][] = $msg_break_timeout;
                    break;
                }

                // Fill return list
                $check_key_treated[] = $nbm_user['check_key'];

                $do_update = true;

                if ($nbm_user['mail_address'] != '') {
                    // set env nbm user
                    self::set_user_on_env_nbm($nbm_user, true);

                    $subject = '[' . $conf['gallery_title'] . '] ' . ($is_subscribe ? functions::l10n('Subscribe to notification by mail') : functions::l10n('Unsubscribe from notification by mail'));

                    // Assign current var for nbm mail
                    self::assign_vars_nbm_mail_content($nbm_user);

                    $section_action_by = ($is_subscribe ? 'subscribe_by_' : 'unsubscribe_by_');
                    $section_action_by .= ($is_admin_request ? 'admin' : 'himself');
                    $env_nbm['mail_template']->assign(
                        [
                            $section_action_by => true,
                            'GOTO_GALLERY_TITLE' => $conf['gallery_title'],
                            'GOTO_GALLERY_URL' => functions_url::get_gallery_home_url(),
                        ]
                    );

                    $ret = functions_mail::pwg_mail(
                        [
                            'name' => stripslashes($nbm_user['username']),
                            'email' => $nbm_user['mail_address'],
                        ],
                        [
                            'from' => $env_nbm['send_as_mail_formated'],
                            'subject' => $subject,
                            'email_format' => $env_nbm['email_format'],
                            'content' => $env_nbm['mail_template']->parse('notification_by_mail', true),
                            'content_format' => $env_nbm['email_format'],
                        ]
                    );

                    if ($ret) {
                        self::inc_mail_sent_success($nbm_user);
                    } else {
                        self::inc_mail_sent_failed($nbm_user);
                        $do_update = false;
                    }

                    // unset env nbm user
                    self::unset_user_on_env_nbm();

                }

                if ($do_update) {
                    $updates[] = [
                        'check_key' => $nbm_user['check_key'],
                        'enabled' => $enabled_value,
                    ];
                    ++$updated_data_count;
                    $page['infos'][] = sprintf($msg_info, stripslashes($nbm_user['username']), $nbm_user['mail_address']);
                } else {
                    ++$error_on_updated_data_count;
                    $page['errors'][] = sprintf($msg_error, stripslashes($nbm_user['username']), $nbm_user['mail_address']);
                }

            }

            // Restore nbm environment
            self::end_users_env_nbm();

            self::display_counter_info();

            functions_mysqli::mass_updates(
                'user_mail_notification',
                [
                    'primary' => ['check_key'],
                    'update' => ['enabled'],
                ],
                $updates
            );

        }

        $page['infos'][] = functions::l10n_dec(
            '%d user was updated.',
            '%d users were updated.',
            $updated_data_count
        );

        if ($error_on_updated_data_count != 0) {
            $page['errors'][] = functions::l10n_dec(
                '%d user was not updated.',
                '%d users were not updated.',
                $error_on_updated_data_count
            );
        }

        functions_url::unset_make_full_url();

        return $check_key_treated;
    }

    /**
     * Unsubscribe notification by mail
     *
     * check_key list where action will be done
     *
     * @return array check_key list treated
     * @throws \Exception
     */
    public static function unsubscribe_notification_by_mail(
        bool $is_admin_request,
        array $check_key_list = []
    ): array {
        return self::do_subscribe_unsubscribe_notification_by_mail($is_admin_request, false, $check_key_list);
    }

    /**
     * Subscribe notification by mail
     *
     * check_key list where action will be done
     *
     * @return array check_key list treated
     * @throws \Exception
     */
    public static function subscribe_notification_by_mail(
        bool $is_admin_request,
        array $check_key_list = []
    ): array {
        return self::do_subscribe_unsubscribe_notification_by_mail($is_admin_request, true, $check_key_list);
    }
}
