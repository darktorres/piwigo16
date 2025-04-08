<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Exception;
use InvalidArgumentException;
use Pelago\Emogrifier\CssInliner;
use PHPMailer\PHPMailer\PHPMailer;
use Piwigo\inc\dblayer\functions_mysqli;
use Random\RandomException;
use SmartyException;
use Symfony\Component\CssSelector\Exception\ParseException;

final class functions_mail
{
    /**
     * Returns the name of the mail sender
     */
    public static function get_mail_sender_name(): string
    {
        global $conf;

        return empty($conf->mail_sender_name) ? $conf->gallery_title : $conf->mail_sender_name;
    }

    /**
     * Returns the email of the mail sender
     */
    public static function get_mail_sender_email(): string
    {
        global $conf;

        return empty($conf->mail_sender_email) ? functions::get_webmaster_mail_address() : $conf->mail_sender_email;
    }

    /**
     * Returns an array of mail configuration parameters.
     * - send_bcc_mail_webmaster
     * - mail_allow_html
     * - use_smtp
     * - smtp_host
     * - smtp_user
     * - smtp_password
     * - smtp_secure
     * - email_webmaster
     * - name_webmaster
     */
    public static function get_mail_configuration(): array
    {
        global $conf;

        $conf_mail = [
            'send_bcc_mail_webmaster' => $conf->send_bcc_mail_webmaster,
            'mail_allow_html' => $conf->mail_allow_html,
            'mail_theme' => $conf->mail_theme,
            'use_smtp' => ! empty($conf->smtp_host),
            'smtp_host' => $conf->smtp_host,
            'smtp_user' => $conf->smtp_user,
            'smtp_password' => $conf->smtp_password,
            'smtp_secure' => $conf->smtp_secure,
            'email_webmaster' => self::get_mail_sender_email(),
            'name_webmaster' => self::get_mail_sender_name(),
        ];

        return $conf_mail;
    }

    /**
     * Returns an email address with an associated real name.
     * Can return either:
     *    - email@domain.com
     *    - name <email@domain.com>
     */
    public static function format_email(
        string $name,
        string $email
    ): string {
        $cvt_email = trim(preg_replace('#[\n\r]+#', '', $email));
        $cvt_name = trim(preg_replace('#[\n\r]+#', '', $name));

        if ($cvt_name !== '') {
            $cvt_name = '"' . addcslashes($cvt_name, '"') . '" ';
        }

        if (! str_contains($cvt_email, '<')) {
            return $cvt_name . '<' . $cvt_email . '>';
        }

        return $cvt_name . $cvt_email;
    }

    /**
     * Returns the email and the name from a formatted address.
     *
     * @param string|array<string> $input - if is an array must contain email[, name]
     * @return array email, name
     */
    public static function unformat_email(
        array|string $input
    ): array {
        if (is_array($input)) {
            if (! isset($input['name'])) {
                $input['name'] = '';
            }

            return $input;
        }

        if (preg_match('/(.*)<(.*)>.*/', $input, $matches)) {
            return [
                'email' => trim($matches[2]),
                'name' => trim($matches[1]),
            ];
        }

        return [
            'email' => trim($input),
            'name' => '',
        ];
    }

    /**
     * Return a clean array of hashmaps (email, name) removing duplicates.
     * It accepts various inputs:
     *    - comma separated list
     *    - array of emails
     *    - single hashmap (email[, name])
     *    - array of incomplete hashmaps
     *
     * @return array<array{email: string, name: string}>
     */
    public static function get_clean_recipients_list(
        array|string $data
    ): array {
        if (empty($data)) {
            return [];
        } elseif (is_array($data)) {
            $values = array_values($data);

            if (! is_array($values[0])) {
                $keys = array_keys($data);

                if (is_int($keys[0])) { // simple array of emails
                    foreach ($data as &$item) {
                        $item = [
                            'email' => trim($item),
                            'name' => '',
                        ];
                    }

                    unset($item);
                } else { // hashmap of one recipient
                    $data = [self::unformat_email($data)];
                }
            } else { // array of hashmaps
                $data = array_map(self::unformat_email(...), $data);
            }
        } else {
            $data = explode(',', $data);
            $data = array_map(self::unformat_email(...), $data);
        }

        $existing = [];

        foreach ($data as $i => $entry) {
            if (isset($existing[$entry['email']])) {
                unset($data[$i]);
            } else {
                $existing[$entry['email']] = true;
            }
        }

        return array_values($data);
    }

    // /**
    //  * Returns an email address list with minimal email string.
    //  * @deprecated 2.6
    //  *
    //  * @param string $email_list - comma separated
    //  */
    // public static function get_strict_email_list(
    //     string $email_list
    // ): string {
    //     $result = [];
    //     $list = explode(',', $email_list);

    //     foreach ($list as $email) {
    //         if (strpos($email, '<') !== false) {
    //             $email = preg_replace('/.*<(.*)>.*/i', '$1', $email);
    //         }

    //         $result[] = trim($email);
    //     }

    //     return implode(',', array_unique($result));
    // }

    /**
     * Return an new mail template.
     *
     * @param string $email_format - text/html or text/plain
     * @throws SmartyException
     */
    public static function &get_mail_template(
        string $email_format
    ): Template {
        $template = new Template('./themes', 'default', 'template/mail/' . $email_format);
        return $template;
    }

    /**
     * Return string email format (text/html or text/plain).
     */
    public static function get_str_email_format(
        bool $is_html
    ): string {
        return $is_html ? 'text/html' : 'text/plain';
    }

    /**
     * Switch language to specified language.
     * All entries are push on language stack
     */
    public static function switch_lang_to(
        string $language
    ): void {
        global $switch_lang, $user, $lang, $lang_info, $language_files;

        // explanation of switch_lang
        // $switch_lang['language'] contains data of language
        // $switch_lang['stack'] contains stack LIFO
        // $switch_lang['initialisation'] allow to know if it's first call

        // Treatment with current user
        // Language of current user is saved (it's considered OK on first call)
        if (! isset($switch_lang['initialisation']) &&
            ! isset($switch_lang['language'][$user['language']])
        ) {
            $switch_lang['initialisation'] = true;
            $switch_lang['language'][$user['language']]['lang_info'] = $lang_info;
            $switch_lang['language'][$user['language']]['lang'] = $lang;
        }

        // Change current infos
        $switch_lang['stack'][] = $user['language'];
        $user['language'] = $language;

        // Load new data if necessary
        if (! isset($switch_lang['language'][$language])) {
            // Re-Init language arrays
            $lang_info = [];
            $lang = [];

            // language files
            functions::load_language('common.lang', '', [
                'language' => $language,
            ]);
            // No test admin because script is checked admin (user selected no)
            // Translations are in admin file too
            functions::load_language('admin.lang', '', [
                'language' => $language,
            ]);

            // Reload all plugins files (see load_language declaration)
            if (! empty($language_files)) {
                foreach ($language_files as $dirname => $files) {
                    foreach ($files as $filename => $options) {
                        $options['language'] = $language;
                        functions::load_language($filename, $dirname, $options);
                    }
                }
            }

            functions_plugins::trigger_notify('loading_lang');
            functions::load_language(
                'lang',
                './local/',
                [
                    'language' => $language,
                    'no_fallback' => true,
                    'local' => true,
                ]
            );

            $switch_lang['language'][$language]['lang_info'] = $lang_info;
            $switch_lang['language'][$language]['lang'] = $lang;
        } else {
            $lang_info = $switch_lang['language'][$language]['lang_info'];
            $lang = $switch_lang['language'][$language]['lang'];
        }
    }

    /**
     * Switch back language pushed with switch_lang_to() function.
     * @see switch_lang_to()
     * Language files are not reloaded
     */
    public static function switch_lang_back(): void
    {
        global $switch_lang, $user, $lang, $lang_info;

        if (count($switch_lang['stack']) > 0) {
            // Get last value
            $language = array_pop($switch_lang['stack']);

            // Change current infos
            if (isset($switch_lang['language'][$language])) {
                $lang_info = $switch_lang['language'][$language]['lang_info'];
                $lang = $switch_lang['language'][$language]['lang'];
            }

            $user['language'] = $language;
        }
    }

    /**
     * Send a notification email to all administrators.
     * current user (if admin) is not notified
     *
     * @param bool $send_technical_details - send user IP and browser
     * @throws Exception
     */
    public static function pwg_mail_notification_admins(
        array|string $subject,
        array|string $content,
        bool $send_technical_details = true,
        string|null $group_id = null
    ): bool {
        if (empty($subject) ||
            empty($content)
        ) {
            return false;
        }

        global $conf, $user;

        if (is_array($subject) ||
            is_array($content)
        ) {
            self::switch_lang_to(functions_user::get_default_language());

            if (is_array($subject)) {
                $subject = functions::l10n_args($subject);
            }

            if (is_array($content)) {
                $content = functions::l10n_args($content);
            }

            self::switch_lang_back();
        }

        $tpl_vars = [];

        if ($send_technical_details) {
            $tpl_vars['TECHNICAL'] = [
                'username' => stripslashes($user['username']),
                'ip' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            ];
        }

        return self::pwg_mail_admins(
            [
                'subject' => '[' . $conf->gallery_title . '] ' . $subject,
                'mail_title' => $conf->gallery_title,
                'mail_subtitle' => $subject,
                'content' => $content,
                'content_format' => 'text/plain',
            ],
            [
                'filename' => 'notification_admin',
                'assign' => $tpl_vars,
            ],
            true, // exclude_current_user
            false, // only_webmasters
            $group_id
        );
    }

    /**
     * Send a email to all administrators.
     * current user (if admin) is excluded
     * @param array $args - as in pwg_mail()
     * @param array $tpl - as in pwg_mail()
     * @throws Exception
     * @see pwg_mail()
     */
    public static function pwg_mail_admins(
        array $args = [],
        array $tpl = [],
        bool $exclude_current_user = true,
        bool $only_webmasters = false,
        ?string $group_id = null
    ): bool {
        if (empty($args['content']) &&
            $tpl === []
        ) {
            return false;
        }

        global $conf, $user;
        $return = true;

        $user_statuses = ['webmaster'];

        if (! $only_webmasters) {
            $user_statuses[] = 'admin';
        }

        // get admins (except ourself)
        $user_statuses_str = implode("','", $user_statuses);
        $query = <<<SQL
            SELECT i.user_id, u.{$conf->user_fields['username']} AS name, u.{$conf->user_fields['email']} AS email
            FROM users AS u
            JOIN user_infos AS i ON i.user_id =  u.{$conf->user_fields['id']}

            SQL;

        if ($group_id !== null) {
            $query .= <<<SQL
                JOIN user_group AS ug ON ug.user_id = i.user_id

                SQL;
        }

        $query .= <<<SQL
            WHERE i.status IN ('{$user_statuses_str}')
                AND u.{$conf->user_fields['email']} IS NOT NULL

            SQL;

        if ($group_id !== null) {
            $query .= <<<SQL
                AND group_id = {$group_id}

                SQL;
        }

        if ($exclude_current_user) {
            $query .= <<<SQL
                AND i.user_id != {$user['id']}

                SQL;
        }

        $query .= <<<SQL
            ORDER BY name;
            SQL;
        $admins = functions_mysqli::query2array($query);

        if ($admins === []) {
            return $return;
        }

        self::switch_lang_to(functions_user::get_default_language());

        $return = self::pwg_mail($admins, $args, $tpl);

        self::switch_lang_back();

        return $return;
    }

    /**
     * Send an email to a group.
     * @param array $args - as in pwg_mail()
     *       o language_selected: filters users of the group by language [default value empty]
     * @param array $tpl - as in pwg_mail()
     * @throws RandomException
     * @see pwg_mail()
     */
    public static function pwg_mail_group(
        int $group_id,
        array $args = [],
        array $tpl = []
    ): bool|int {
        if (empty($group_id) ||
           (empty($args['content']) && $tpl === [])
        ) {
            return false;
        }

        global $conf;
        $return = true;

        // get distinct languages of targeted users
        $query = <<<SQL
            SELECT DISTINCT language
            FROM user_group AS ug
            INNER JOIN users AS u ON {$conf->user_fields['id']} = ug.user_id
            INNER JOIN user_infos AS ui ON ui.user_id = ug.user_id
            WHERE group_id = {$group_id}
                AND {$conf->user_fields['email']} != ""

            SQL;

        if (! empty($args['language_selected'])) {
            $query .= <<<SQL
                AND language = '{$args['language_selected']}'

                SQL;
        }

        $query = trim($query) . ';';
        $languages = functions_mysqli::query2array($query, null, 'language');

        if ($languages === []) {
            return $return;
        }

        foreach ($languages as $language) {
            // get subset of users in this group for a specific language
            $query = <<<SQL
                SELECT ui.user_id, ui.status, u.{$conf->user_fields['username']} AS name, u.{$conf->user_fields['email']} AS email
                FROM user_group AS ug
                INNER JOIN users AS u ON {$conf->user_fields['id']} = ug.user_id
                INNER JOIN user_infos AS ui ON ui.user_id = ug.user_id
                WHERE group_id = {$group_id}
                    AND {$conf->user_fields['email']} != ""
                    AND language = '{$language}';
                SQL;
            $users = functions_mysqli::query2array($query);

            if ($users === []) {
                continue;
            }

            self::switch_lang_to($language);

            foreach ($users as $u) {
                $authkey = functions_user::create_user_auth_key($u['user_id'], $u['status']);

                $user_tpl = $tpl;

                if ($authkey !== false) {
                    $user_tpl['assign']['LINK'] = functions_url::add_url_params($tpl['assign']['LINK'], [
                        'auth' => $authkey['auth_key'],
                    ]);

                    if (isset($user_tpl['assign']['IMG']['link'])) {
                        $user_tpl['assign']['IMG']['link'] = functions_url::add_url_params(
                            $user_tpl['assign']['IMG']['link'],
                            [
                                'auth' => $authkey['auth_key'],
                            ]
                        );
                    }
                }

                $user_args = $args;

                if ($authkey !== false) {
                    $user_args['auth_key'] = $authkey['auth_key'];
                }

                $return &= self::pwg_mail($u['email'], $user_args, $user_tpl);
            }

            self::switch_lang_back();
        }

        return $return;
    }

    /**
     * Sends an email, using Piwigo specific information.
     *
     * @param array $args
     *       o from: sender [default value webmaster email]
     *       o Cc: array of carbon copy receivers of the mail. [default value empty]
     *       o Bcc: array of blind carbon copy receivers of the mail. [default value empty]
     *       o subject [default value 'Piwigo']
     *       o content: content of mail [default value '']
     *       o content_format: format of mail content [default value 'text/plain']
     *       o email_format: global mail format [default value $conf_mail['default_email_format']]
     *       o theme: theme to use [default value $conf_mail['mail_theme']]
     *       o mail_title: main title of the mail [default value $conf->gallery_title]
     *       o mail_subtitle: subtitle of the mail [default value subject]
     *       o auth_key: authentication key to add on footer link [default value null]
     * @param array $tpl - use these options to define a custom content template file
     *       o filename
     *       o dirname (optional)
     *       o assign (optional)
     *
     * @throws Exception
     */
    public static function pwg_mail(
        array|string $to,
        array $args = [],
        array $tpl = []
    ): bool {
        global $conf, $conf_mail, $lang_info, $page;

        if (empty($to) &&
            empty($args['Cc']) &&
            empty($args['Bcc'])
        ) {
            return true;
        }

        if (! isset($conf_mail)) {
            $conf_mail = self::get_mail_configuration();
        }

        $mail = new PHPMailer();

        foreach (self::get_clean_recipients_list($to) as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name']);
        }

        $mail->WordWrap = 76;
        $mail->CharSet = 'UTF-8';

        // Compute root_path in order have complete path
        functions_url::set_make_full_url();

        if (empty($args['from'])) {
            $from = [
                'email' => $conf_mail['email_webmaster'],
                'name' => $conf_mail['name_webmaster'],
            ];
        } else {
            $from = self::unformat_email($args['from']);
        }

        $mail->setFrom($from['email'], $from['name']);
        $mail->addReplyTo($from['email'], $from['name']);

        // Subject
        if (empty($args['subject'])) {
            $args['subject'] = 'Piwigo';
        }

        $args['subject'] = trim(preg_replace('#[\n\r]+#', '', $args['subject']));
        $mail->Subject = $args['subject'];

        // Cc
        if (! empty($args['Cc'])) {
            foreach (self::get_clean_recipients_list($args['Cc']) as $recipient) {
                $mail->addCC($recipient['email'], $recipient['name']);
            }
        }

        // Bcc
        $Bcc = self::get_clean_recipients_list(($args['Bcc'] ?? null));

        if ($conf_mail['send_bcc_mail_webmaster']) {
            $Bcc[] = [
                'email' => functions::get_webmaster_mail_address(),
                'name' => '',
            ];
        }

        if ($Bcc !== []) {
            foreach ($Bcc as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name']);
            }
        }

        // theme
        if (empty($args['theme']) ||
            ! in_array($args['theme'], ['clear', 'dark'])
        ) {
            $args['theme'] = $conf_mail['mail_theme'];
        }

        // content
        if (! isset($args['content'])) {
            $args['content'] = '';
        }

        // try to decompose subject like "[....] ...."
        if (! isset($args['mail_title']) && ! isset($args['mail_subtitle']) && preg_match('#^\[(.*)\](.*)$#', $args['subject'], $matches)) {
            $args['mail_title'] = $matches[1];
            $args['mail_subtitle'] = $matches[2];
        }

        if (! isset($args['mail_title'])) {
            $args['mail_title'] = $conf->gallery_title;
        }

        if (! isset($args['mail_subtitle'])) {
            $args['mail_subtitle'] = $args['subject'];
        }

        // content type
        if (empty($args['content_format'])) {
            $args['content_format'] = 'text/plain';
        }

        $content_type_list = [];
        if ($conf_mail['mail_allow_html'] &&
            ($args['email_format'] ?? null) != 'text/plain'
        ) {
            $content_type_list[] = 'text/html';
        }

        $content_type_list[] = 'text/plain';

        $contents = [];

        foreach ($content_type_list as $content_type) {
            // key compose of indexes witch allow to cache mail data
            $cache_key = $content_type . '-' . $lang_info['code'];

            if (! empty($args['auth_key'])) {
                $cache_key .= '-' . $args['auth_key'];
            }

            if (! isset($conf_mail[$cache_key])) {
                // instantiate a new Template
                if (! isset($conf_mail[$cache_key]['theme'])) {
                    $conf_mail[$cache_key]['theme'] = self::get_mail_template($content_type);
                    functions_plugins::trigger_notify('before_parse_mail_template', $cache_key, $content_type);
                }

                $template = &$conf_mail[$cache_key]['theme'];

                $template->set_filename('mail_header', 'header.tpl');
                $template->set_filename('mail_footer', 'footer.tpl');

                $add_url_params = [];

                if (! empty($args['auth_key'])) {
                    $add_url_params['auth'] = $args['auth_key'];
                }

                $template->assign(
                    [
                        'GALLERY_URL' => functions_url::add_url_params(functions_url::get_gallery_home_url(), $add_url_params),
                        'GALLERY_TITLE' => $page['gallery_title'] ?? $conf->gallery_title,
                        'VERSION' => $conf->show_version ? PHPWG_VERSION : '',
                        'PHPWG_URL' => defined('PHPWG_URL') ? PHPWG_URL : '',
                        'CONTENT_ENCODING' => functions::get_pwg_charset(),
                        'CONTACT_MAIL' => $conf_mail['email_webmaster'],
                    ]
                );

                if ($content_type === 'text/html') {
                    if ($template->smarty->templateExists('global-mail-css.tpl')) {
                        $template->set_filename('global-css', 'global-mail-css.tpl');
                        $template->assign_var_from_handle('GLOBAL_MAIL_CSS', 'global-css');
                    }

                    if ($template->smarty->templateExists('mail-css-' . $args['theme'] . '.tpl')) {
                        $template->set_filename('css', 'mail-css-' . $args['theme'] . '.tpl');
                        $template->assign_var_from_handle('MAIL_CSS', 'css');
                    }
                }
            }

            $template = &$conf_mail[$cache_key]['theme'];
            $template->assign(
                [
                    'MAIL_TITLE' => $args['mail_title'],
                    'MAIL_SUBTITLE' => $args['mail_subtitle'],
                ]
            );

            // Header
            $contents[$content_type] = $template->parse('mail_header', true);

            // Content
            // Stored in a temp variable, if a content template is used it will be assigned
            // to the $CONTENT template variable, otherwise it will be appended to the mail
            if ($args['content_format'] == 'text/plain' &&
                $content_type === 'text/html'
            ) {
                // convert plain text to html
                $mail_content =
                  '<p>' .
                  nl2br(
                      preg_replace(
                          '/(https?:\/\/([-\w\.]+[-\w])+(:\d+)?(\/([\w\/_\.\#-]*(\?\S+)?[^\.\s])?)?)/i',
                          '<a href="$1">$1</a>',
                          htmlspecialchars($args['content'])
                      )
                  ) .
                  '</p>';
            } elseif ($args['content_format'] == 'text/html' &&
                      $content_type === 'text/plain'
            ) {
                // convert html text to plain text
                $mail_content = strip_tags($args['content']);
            } else {
                $mail_content = $args['content'];
            }

            // Runtime template
            if (isset($tpl['filename'])) {
                if (isset($tpl['dirname'])) {
                    $template->set_template_dir($tpl['dirname'] . '/' . $content_type);
                }

                if ($template->smarty->templateExists($tpl['filename'] . '.tpl')) {
                    $template->set_filename($tpl['filename'], $tpl['filename'] . '.tpl');

                    if (! empty($tpl['assign'])) {
                        $template->assign($tpl['assign']);
                    }

                    $template->assign('CONTENT', $mail_content);
                    $contents[$content_type] .= $template->parse($tpl['filename'], true);
                } else {
                    $contents[$content_type] .= $mail_content;
                }
            } else {
                $contents[$content_type] .= $mail_content;
            }

            // Footer
            $contents[$content_type] .= $template->parse('mail_footer', true);
        }

        // Undo Compute root_path in order have complete path
        functions_url::unset_make_full_url();

        // Send content to PHPMailer
        if (isset($contents['text/html'])) {
            $mail->isHTML();
            $mail->Body = self::move_css_to_body($contents['text/html']);

            if (isset($contents['text/plain'])) {
                $mail->AltBody = $contents['text/plain'];
            }
        } else {
            $mail->isHTML(false);
            $mail->Body = $contents['text/plain'];
        }

        if ($conf_mail['use_smtp']) {
            // now we need to split port number
            if (str_contains($conf_mail['smtp_host'], ':')) {
                [$smtp_host, $smtp_port] = explode(':', $conf_mail['smtp_host']);
            } else {
                $smtp_host = $conf_mail['smtp_host'];
                $smtp_port = 25;
            }

            $mail->isSMTP();

            // enables SMTP debug information (for testing) 2 - debug, 0 - no message
            $mail->SMTPDebug = 0;

            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;

            if (! empty($conf_mail['smtp_secure']) &&
                in_array($conf_mail['smtp_secure'], ['ssl', 'tls'])
            ) {
                $mail->SMTPSecure = $conf_mail['smtp_secure'];
            }

            if (! empty($conf_mail['smtp_user'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $conf_mail['smtp_user'];
                $mail->Password = $conf_mail['smtp_password'];
            }
        }

        $ret = true;
        $pre_result = functions_plugins::trigger_change('before_send_mail', true, $to, $args, $mail);

        if ($pre_result) {
            $ret = $mail->send();

            if (! $ret &&
               (! ini_get('display_errors') || functions_user::is_admin())
            ) {
                trigger_error('Mailer Error: ' . $mail->ErrorInfo, E_USER_WARNING);
            }

            if ($conf->debug_mail) {
                self::pwg_send_mail_test($ret, $mail, $args);
            }
        }

        return $ret;
    }

    // /**
    //  * @deprecated 2.6
    //  */
    // public static function pwg_send_mail(
    //     bool $result,
    //     array|string $to,
    //     string $subject,
    //     string $content,
    //     array|string $headers
    // ): bool {
    //     if (functions_user::is_admin()) {
    //         trigger_error('pwg_send_mail function is deprecated', E_USER_NOTICE);
    //     }

    //     if (! $result) {
    //         return self::pwg_mail($to, [
    //             'content' => $content,
    //             'subject' => $subject,
    //         ]);
    //     }

    //     return $result;
    // }

    /**
     * Moves CSS rules contained in the <style> tag to inline CSS.
     * Used for compatibility with Gmail and such clients
     * @throws InvalidArgumentException
     * @throws ParseException
     */
    public static function move_css_to_body(
        string $content
    ): string {
        return CssInliner::fromHtml($content)->inlineCss()->render();
    }

    /**
     * Saves a copy of the mail if _data/tmp.
     */
    public static function pwg_send_mail_test(
        bool $success,
        PHPMailer $mail,
        array $args
    ): void {
        global $conf, $user, $lang_info;

        $dir = './' . $conf->data_location . 'tmp';

        if (functions::mkgetdir($dir, functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR)) {
            $filename = $dir . '/mail.' . stripslashes($user['username']) . '.' . $lang_info['code'] . '-' . date('YmdHis') . ($success ? '' : '.ERROR');

            if ($args['content_format'] == 'text/plain') {
                $filename .= '.txt';
            } else {
                $filename .= '.html';
            }

            $file = fopen($filename, 'w+');

            if (! $success) {
                fwrite($file, 'ERROR: ' . $mail->ErrorInfo . "\n\n");
            }

            fwrite($file, $mail->getSentMIMEMessage());
            fclose($file);
        }
    }
}

functions_plugins::trigger_notify('functions_mail_included');
