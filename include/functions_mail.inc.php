<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Returns the name of the mail sender
 *
 * @return string
 */
function get_mail_sender_name()
{
    global $conf;

    return empty($conf['mail_sender_name']) ? $conf['gallery_title'] : $conf['mail_sender_name'];
}

/**
 * Returns the email of the mail sender
 *
 * @since 2.6
 * @return string
 */
function get_mail_sender_email()
{
    global $conf;

    return empty($conf['mail_sender_email']) ? get_webmaster_mail_address() : $conf['mail_sender_email'];
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
 *
 * @return array<string, mixed>
 */
function get_mail_configuration(): array
{
    global $conf;

    $conf_mail = [
        'send_bcc_mail_webmaster' => $conf['send_bcc_mail_webmaster'],
        'mail_allow_html' => $conf['mail_allow_html'],
        'mail_theme' => $conf['mail_theme'],
        'use_smtp' => ! empty($conf['smtp_host']),
        'smtp_host' => $conf['smtp_host'],
        'smtp_user' => $conf['smtp_user'],
        'smtp_password' => $conf['smtp_password'],
        'smtp_secure' => $conf['smtp_secure'],
        'email_webmaster' => get_mail_sender_email(),
        'name_webmaster' => get_mail_sender_name(),
    ];

    return $conf_mail;
}

/**
 * Returns an email address with an associated real name.
 * Can return either:
 *    - email@domain.com
 *    - name <email@domain.com>
 *
 * @param string $name
 * @param string $email
 */
function format_email($name, $email): string
{
    $cvt_email = trim((string) preg_replace('#[\n\r]+#s', '', $email));
    $cvt_name = trim((string) preg_replace('#[\n\r]+#s', '', $name));

    if ($cvt_name != '') {
        $cvt_name = '"' . addcslashes($cvt_name, '"') . '" ';
    }

    if (! str_contains($cvt_email, '<')) {
        return $cvt_name . '<' . $cvt_email . '>';
    } else {
        return $cvt_name . $cvt_email;
    }
}

/**
 * Returns the email and the name from a formatted address.
 * @since 2.6
 *
 * @param string|array<int|string, mixed> $input - if is an array must contain email[, name]
 * @return array{email: string, name: string}
 */
function unformat_email($input): array
{
    if (is_array($input)) {
        if (! isset($input['email']) || ! is_string($input['email'])) {
            throw new InvalidArgumentException(__FUNCTION__ . '(): array input must contain a string "email" key');
        }

        return [
            'email' => $input['email'],
            'name' => isset($input['name']) && is_string($input['name']) ? $input['name'] : '',
        ];
    }

    if (preg_match('/(.*)<(.*)>.*/', $input, $matches)) {
        return [
            'email' => trim($matches[2]),
            'name' => trim($matches[1]),
        ];
    } else {
        return [
            'email' => trim($input),
            'name' => '',
        ];
    }
}

/**
 * Return a clean array of hashmaps (email, name) removing duplicates.
 * It accepts various inputs:
 *    - comma separated list
 *    - array of emails
 *    - single hashmap (email[, name])
 *    - array of incomplete hashmaps
 * @since 2.6
 *
 * @param mixed $data
 * @return list<array{email: string, name: string}>
 */
function get_clean_recipients_list($data): array
{
    if (empty($data)) {
        return [];
    }

    // Every branch below funnels into $entries rather than reusing/mutating
    // $data in place: $data starts as mixed, and PHPStan cannot follow a
    // by-reference foreach mutation or an array_map() over still-mixed
    // elements back into a precise array{email,name} shape.
    $entries = [];

    if (is_array($data)) {
        $values = array_values($data);
        if (! is_array($values[0])) {
            $keys = array_keys($data);
            if (is_int($keys[0])) { // simple array of emails
                foreach ($data as $item) {
                    $entries[] = [
                        'email' => trim(is_scalar($item) ? (string) $item : ''),
                        'name' => '',
                    ];
                }
            } else { // hashmap of one recipient
                $entries[] = unformat_email($data);
            }
        } else { // array of hashmaps
            foreach ($data as $item) {
                if (is_array($item) || is_string($item)) {
                    $entries[] = unformat_email($item);
                } else {
                    $entries[] = [
                        'email' => is_scalar($item) ? trim((string) $item) : '',
                        'name' => '',
                    ];
                }
            }
        }
    } else {
        $list = explode(',', is_scalar($data) ? (string) $data : '');
        foreach ($list as $item) {
            $entries[] = unformat_email($item);
        }
    }

    $existing = [];
    $result = [];
    foreach ($entries as $entry) {
        if (isset($existing[$entry['email']])) {
            continue;
        }
        $existing[$entry['email']] = true;
        $result[] = $entry;
    }

    return $result;
}

/**
 * Returns an email address list with minimal email string.
 *
 * @param string $email_list - comma separated
 */
#[\Deprecated(message: '2.6')]
function get_strict_email_list($email_list): string
{
    $result = [];
    $list = explode(',', $email_list);

    foreach ($list as $email) {
        if (str_contains($email, '<')) {
            $email = preg_replace('/.*<(.*)>.*/i', '$1', $email);
        }
        $result[] = trim((string) $email);
    }

    return implode(',', array_unique($result));
}

/**
 * Return an new mail template.
 *
 * @param string $email_format - text/html or text/plain
 */
function &get_mail_template($email_format): \Template
{
    $template = new Template(PHPWG_ROOT_PATH . 'themes', 'default', 'template/mail/' . $email_format);
    return $template;
}

/**
 * Return string email format (text/html or text/plain).
 *
 * @param bool $is_html
 */
function get_str_email_format($is_html): string
{
    return $is_html ? 'text/html' : 'text/plain';
}

/**
 * Switch language to specified language.
 * All entries are push on language stack
 *
 * @param string $language
 */
function switch_lang_to($language): void
{
    global $switch_lang, $user, $lang, $lang_info, $language_files;

    // explanation of switch_lang
    // $switch_lang['language'] contains data of language
    // $switch_lang['stack'] contains stack LIFO
    // $switch_lang['initialisation'] allow to know if it's first call

    // Treatment with current user
    // Language of current user is saved (it's considered OK on firt call)
    if (! isset($switch_lang['initialisation']) and ! isset($switch_lang['language'][$user['language']])) {
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
        load_language('common.lang', '', [
            'language' => $language,
        ]);
        // No test admin because script is checked admin (user selected no)
        // Translations are in admin file too
        load_language('admin.lang', '', [
            'language' => $language,
        ]);

        // Reload all plugins files (see load_language declaration)
        if (! empty($language_files)) {
            foreach ($language_files as $dirname => $files) {
                foreach ($files as $filename => $options) {
                    $options['language'] = $language;
                    load_language($filename, $dirname, $options);
                }
            }
        }

        trigger_notify('loading_lang');
        load_language(
            'lang',
            PHPWG_ROOT_PATH . PWG_LOCAL_DIR,
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
function switch_lang_back(): void
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
 * @param string|array<int|string, mixed> $subject
 * @param string|array<int|string, mixed> $content
 * @param bool $send_technical_details - send user IP and browser
 * @param int|string|null $group_id
 * @return bool
 */
function pwg_mail_notification_admins($subject, $content, $send_technical_details = true, $group_id = null)
{
    if (empty($subject) or empty($content)) {
        return false;
    }

    global $conf, $user;

    if (is_array($subject) or is_array($content)) {
        switch_lang_to(get_default_language());

        if (is_array($subject)) {
            $subject = l10n_args($subject);
        }
        if (is_array($content)) {
            $content = l10n_args($content);
        }

        switch_lang_back();
    }

    $tpl_vars = [];
    if ($send_technical_details) {
        $tpl_vars['TECHNICAL'] = [
            'username' => stripslashes((string) $user['username']),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        ];
    }

    return pwg_mail_admins(
        [
            'subject' => '[' . $conf['gallery_title'] . '] ' . $subject,
            'mail_title' => $conf['gallery_title'],
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
 * @see pwg_mail()
 * @since 2.6
 *
 * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args - as in pwg_mail()
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - as in pwg_mail()
 * @param int|string|null $group_id
 * @return bool
 */
function pwg_mail_admins(array $args = [], array $tpl = [], bool $exclude_current_user = true, bool $only_webmasters = false, $group_id = null)
{
    if (empty($args['content']) and empty($tpl)) {
        return false;
    }

    global $conf, $user;
    $return = true;

    $user_statuses = ['webmaster'];
    if (! $only_webmasters) {
        $user_statuses[] = 'admin';
    }

    // get admins (except ourself)
    $query = '
SELECT
    i.user_id,
    u.' . $conf['user_fields']['username'] . ' AS name,
    u.' . $conf['user_fields']['email'] . ' AS email
  FROM ' . USERS_TABLE . ' AS u
    JOIN ' . USER_INFOS_TABLE . ' AS i
    ON i.user_id =  u.' . $conf['user_fields']['id'];

    if ($group_id !== null) {
        $query .= '
    JOIN ' . USER_GROUP_TABLE . ' AS ug
      ON ug.user_id = i.user_id';
    }

    $query .= '
  WHERE i.status in (\'' . implode("','", $user_statuses) . '\')
    AND u.' . $conf['user_fields']['email'] . ' IS NOT NULL';

    if ($group_id !== null) {
        $query .= '
    AND group_id = ' . intval($group_id);
    }

    if ($exclude_current_user) {
        $query .= '
    AND i.user_id <> ' . $user['id'];
    }

    $query .= '
  ORDER BY name
;';
    $admins = array_from_query($query);

    if (empty($admins)) {
        return $return;
    }

    switch_lang_to(get_default_language());

    $return = pwg_mail($admins, $args, $tpl);

    switch_lang_back();

    return $return;
}

/**
 * Send an email to a group.
 * @see pwg_mail()
 *
 * @param int $group_id
 * @param array{language_selected?: string, from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args - as in pwg_mail()
 *       o language_selected: filters users of the group by language [default value empty]
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - as in pwg_mail()
 * @return bool
 */
function pwg_mail_group($group_id, array $args = [], array $tpl = []): bool|int
{
    if (empty($group_id) or (empty($args['content']) and empty($tpl))) {
        return false;
    }

    global $conf;
    $return = true;

    // get distinct languages of targeted users
    $query = '
SELECT DISTINCT language
  FROM ' . USER_GROUP_TABLE . ' AS ug
    INNER JOIN ' . USERS_TABLE . ' AS u
    ON ' . $conf['user_fields']['id'] . ' = ug.user_id
    INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = ' . $group_id . '
    AND ' . $conf['user_fields']['email'] . ' <> ""';
    if (! empty($args['language_selected'])) {
        $query .= '
    AND language = \'' . $args['language_selected'] . '\'';
    }

    $query .= '
;';
    $languages = array_from_query($query, 'language');

    if (empty($languages)) {
        return $return;
    }

    foreach ($languages as $language) {
        // array_from_query()'s own docblock (include/functions.inc.php) only
        // declares array<int|string, mixed> regardless of $fieldname, so
        // each $language here is mixed even though the 'language' column is
        // never NULL in practice; guard defensively rather than trust it.
        if (! is_string($language) || $language === '') {
            continue;
        }

        // get subset of users in this group for a specific language
        $query = '
SELECT
    ui.user_id,
    ui.status,
    u.' . $conf['user_fields']['username'] . ' AS name,
    u.' . $conf['user_fields']['email'] . ' AS email
  FROM ' . USER_GROUP_TABLE . ' AS ug
    INNER JOIN ' . USERS_TABLE . ' AS u
    ON ' . $conf['user_fields']['id'] . ' = ug.user_id
    INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = ' . $group_id . '
    AND ' . $conf['user_fields']['email'] . ' <> ""
    AND language = \'' . $language . '\'
;';
        $users = array_from_query($query);

        if (empty($users)) {
            continue;
        }

        switch_lang_to($language);

        foreach ($users as $u) {
            // Same array_from_query() under-typing as $languages above: each
            // row is declared mixed, so every field access below needs a
            // real runtime guard.
            if (! is_array($u)) {
                continue;
            }

            $u_user_id = $u['user_id'] ?? null;
            if (! is_numeric($u_user_id)) {
                continue;
            }
            $u_status = $u['status'] ?? null;
            $u_status = is_string($u_status) ? $u_status : null;
            $u_email = $u['email'] ?? null;
            $u_email = is_string($u_email) ? $u_email : '';

            $authkey = create_user_auth_key((int) $u_user_id, $u_status);

            $user_tpl = $tpl;

            if ($authkey !== false) {
                $link = $tpl['assign']['LINK'] ?? null;
                $user_tpl['assign']['LINK'] = add_url_params(is_string($link) ? $link : '', [
                    'auth' => $authkey['auth_key'] ?? null,
                ]);

                $img = $user_tpl['assign']['IMG'] ?? null;
                if (is_array($img) && isset($img['link']) && is_string($img['link'])) {
                    $img['link'] = add_url_params(
                        $img['link'],
                        [
                            'auth' => $authkey['auth_key'] ?? null,
                        ]
                    );
                    $user_tpl['assign']['IMG'] = $img;
                }
            }

            $user_args = $args;
            if ($authkey !== false) {
                $auth_key = $authkey['auth_key'] ?? null;
                if (is_string($auth_key)) {
                    $user_args['auth_key'] = $auth_key;
                }
            }

            $return &= pwg_mail($u_email, $user_args, $user_tpl);
        }

        switch_lang_back();
    }

    return $return;
}

/**
 * Sends an email, using Piwigo specific informations.
 *
 * @param string|array<int|string, mixed> $to
 * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
 *       o from: sender [default value webmaster email]
 *       o reply_to_mail_address: reply-to can be different of the "from" (new 16.4.0) [default value empty]
 *       o reply_to_name: reply-to can be different of the "from" (new 16.4.0) [default value empty]
 *       o Cc: array of carbon copy receivers of the mail. [default value empty]
 *       o Bcc: array of blind carbon copy receivers of the mail. [default value empty]
 *       o subject [default value 'Piwigo']
 *       o content: content of mail [default value '']
 *       o content_format: format of mail content [default value 'text/plain']
 *       o email_format: global mail format [default value $conf_mail['default_email_format']]
 *       o theme: theme to use [default value $conf_mail['mail_theme']]
 *       o mail_title: main title of the mail [default value $conf['gallery_title']]
 *       o mail_subtitle: subtitle of the mail [default value subject]
 *       o auth_key: authentication key to add on footer link [default value null]
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - use these options to define a custom content template file
 *       o filename
 *       o dirname (optional)
 *       o assign (optional)
 *
 * @return bool
 */
function pwg_mail($to, array $args = [], array $tpl = [])
{
    global $conf, $conf_mail, $lang_info, $page;

    if (empty($to) and empty($args['Cc']) and empty($args['Bcc'])) {
        return true;
    }

    if (! isset($conf_mail)) {
        $conf_mail = get_mail_configuration();
    }

    $email = new Email();

    foreach (get_clean_recipients_list($to) as $recipient) {
        $email->addTo(new Address($recipient['email'], $recipient['name']));
    }

    // Compute root_path in order have complete path
    set_make_full_url();

    if (empty($args['from'])) {
        $from = [
            'email' => $conf_mail['email_webmaster'],
            'name' => $conf_mail['name_webmaster'],
        ];
    } else {
        // $args['from'] is declared `mixed` in the shape above since callers
        // may pass either a string or an array{email[, name]}; anything
        // else (e.g. an int/bool/object) falls back to an empty string,
        // which unformat_email() turns into an empty email/name pair.
        $from_input = $args['from'];
        if (! is_array($from_input) && ! is_string($from_input)) {
            $from_input = is_scalar($from_input) ? (string) $from_input : '';
        }
        $from = unformat_email($from_input);
    }
    $email->from(new Address($from['email'], $from['name']));
    $email->replyTo(new Address($args['reply_to_mail_address'] ?? $from['email'], $args['reply_to_name'] ?? $from['name']));

    // Subject
    if (empty($args['subject'])) {
        $args['subject'] = 'Piwigo';
    }
    $subject_input = is_scalar($args['subject']) ? (string) $args['subject'] : '';
    $args['subject'] = trim((string) preg_replace('#[\n\r]+#s', '', $subject_input));
    $email->subject($args['subject']);

    // Cc
    if (! empty($args['Cc'])) {
        foreach (get_clean_recipients_list($args['Cc']) as $recipient) {
            $email->addCc(new Address($recipient['email'], $recipient['name']));
        }
    }

    // Bcc
    $Bcc = get_clean_recipients_list($args['Bcc'] ?? null);
    if ($conf_mail['send_bcc_mail_webmaster']) {
        $Bcc[] = [
            'email' => get_webmaster_mail_address(),
            'name' => '',
        ];
    }
    if (! empty($Bcc)) {
        foreach ($Bcc as $recipient) {
            $email->addBcc(new Address($recipient['email'], $recipient['name']));
        }
    }

    // theme
    if (empty($args['theme']) or ! in_array($args['theme'], ['clear', 'dark'])) {
        $args['theme'] = $conf_mail['mail_theme'];
    }

    // content
    if (! isset($args['content'])) {
        $args['content'] = '';
    }

    // try to decompose subject like "[....] ...."
    if (! isset($args['mail_title']) and ! isset($args['mail_subtitle'])) {
        if (preg_match('#^\[(.*)\](.*)$#', $args['subject'], $matches)) {
            $args['mail_title'] = $matches[1];
            $args['mail_subtitle'] = $matches[2];
        }
    }
    if (! isset($args['mail_title'])) {
        $args['mail_title'] = $conf['gallery_title'];
    }
    if (! isset($args['mail_subtitle'])) {
        $args['mail_subtitle'] = $args['subject'];
    }

    // content type
    if (empty($args['content_format'])) {
        $args['content_format'] = 'text/plain';
    }

    $content_type_list = [];
    if ($conf_mail['mail_allow_html'] and ($args['email_format'] ?? null) != 'text/plain') {
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
            // instanciate a new Template
            if (! isset($conf_mail[$cache_key]['theme'])) {
                $conf_mail[$cache_key]['theme'] = get_mail_template($content_type);
                trigger_notify('before_parse_mail_template', $cache_key, $content_type);
            }
            $template = &$conf_mail[$cache_key]['theme'];

            $template->set_filename('mail_header', 'header.tpl');
            $template->set_filename('mail_footer', 'footer.tpl');

            $add_url_params = [];
            if (! empty($args['auth_key'])) {
                $add_url_params['auth'] = $args['auth_key'];
            }

            // get_gallery_home_url() is declared to return `mixed`
            // (include/functions_url.inc.php); every real branch of its
            // body actually returns a string, so this narrows locally
            // rather than widening add_url_params()'s $url parameter.
            $gallery_home_url = get_gallery_home_url();
            $gallery_home_url = is_string($gallery_home_url) ? $gallery_home_url : '';

            $template->assign(
                [
                    'GALLERY_URL' => add_url_params($gallery_home_url, $add_url_params),
                    'GALLERY_TITLE' => $page['gallery_title'] ?? $conf['gallery_title'],
                    'VERSION' => $conf['show_version'] ? PHPWG_VERSION : '',
                    'PHPWG_URL' => defined('PHPWG_URL') ? PHPWG_URL : '',
                    'CONTENT_ENCODING' => get_pwg_charset(),
                    'CONTACT_MAIL' => $conf_mail['email_webmaster'],
                ]
            );

            if ($content_type == 'text/html') {
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
        // to the $CONTENT template variable, otherwise it will be appened to the mail
        // $args['content'] is declared `mixed` in the shape above (the
        // caller-supplied value is not otherwise constrained); every real
        // caller passes a string, but fall back to '' rather than cast a
        // non-scalar value (array/object) straight to string.
        $content_input = is_scalar($args['content']) ? (string) $args['content'] : '';

        if ($args['content_format'] == 'text/plain' and $content_type == 'text/html') {
            // convert plain text to html
            $mail_content =
              '<p>' .
              nl2br(
                  (string) preg_replace(
                      '/(https?:\/\/([-\w\.]+[-\w])+(:\d+)?(\/([\w\/_\.\#-]*(\?\S+)?[^\.\s])?)?)/i',
                      '<a href="$1">$1</a>',
                      htmlspecialchars($content_input)
                  )
              ) .
              '</p>';
        } elseif ($args['content_format'] == 'text/html' and $content_type == 'text/plain') {
            // convert html text to plain text
            $mail_content = strip_tags($content_input);
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
    unset_make_full_url();

    // Send content
    // 'text/plain' is always present in $contents — $content_type_list
    // (above) unconditionally includes it; 'text/html' is conditional.
    if (isset($contents['text/html'])) {
        $email->html(move_css_to_body($contents['text/html']));
    }
    $email->text($contents['text/plain']);

    if ($conf_mail['use_smtp']) {
        // now we need to split port number
        if (str_contains((string) $conf_mail['smtp_host'], ':')) {
            [$smtp_host, $smtp_port] = explode(':', (string) $conf_mail['smtp_host']);
        } else {
            $smtp_host = $conf_mail['smtp_host'];
            $smtp_port = 25;
        }

        $dsn_auth = '';
        if (! empty($conf_mail['smtp_user'])) {
            $dsn_auth = rawurlencode((string) $conf_mail['smtp_user']) . ':' . rawurlencode((string) $conf_mail['smtp_password']) . '@';
        }

        $dsn = 'smtp://' . $dsn_auth . $smtp_host . ':' . $smtp_port;

        if (! empty($conf_mail['smtp_secure']) and in_array($conf_mail['smtp_secure'], ['ssl', 'tls'])) {
            $dsn .= '?encryption=' . $conf_mail['smtp_secure'];
        }
    } else {
        // matches PHPMailer's default (non-SMTP) behavior, which sends via PHP's native mail()
        $dsn = 'native://default';
    }

    $mailer = new Mailer(Transport::fromDsn($dsn));

    $ret = true;
    $error_message = null;
    $pre_result = trigger_change('before_send_mail', true, $to, $args, $email);

    if ($pre_result) {
        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $ret = false;
            $error_message = $e->getMessage();
        }

        if (! $ret and (! ini_get('display_errors') or is_admin())) {
            trigger_error('Mailer Error: ' . $error_message, E_USER_WARNING);
        }
        if ($conf['debug_mail']) {
            pwg_send_mail_test($ret, $email, $args, $error_message);
        }
    }

    return $ret;
}

/**
 * @param mixed $result
 * @param string|array<int|string, mixed> $to
 * @param mixed $subject
 * @param mixed $content
 * @param mixed $headers unused
 */
#[\Deprecated(message: '2.6')]
function pwg_send_mail($result, $to, $subject, $content, $headers): mixed
{
    if (is_admin()) {
        trigger_error('pwg_send_mail function is deprecated', E_USER_NOTICE);
    }

    if (! $result) {
        return pwg_mail($to, [
            'content' => $content,
            'subject' => $subject,
        ]);
    } else {
        return $result;
    }
}

/**
 * Moves CSS rules contained in the <style> tag to inline CSS.
 * Used for compatibility with Gmail and such clients
 * @since 2.6
 *
 * @return string
 */
function move_css_to_body(string $content)
{
    if ($content === '') {
        return $content;
    }

    try {
        return \Pelago\Emogrifier\CssInliner::fromHtml($content)->inlineCss()->render();
    } catch (\Exception) {
        return $content;
    }
}

/**
 * Saves a copy of the mail if _data/tmp.
 *
 * @param bool $success
 * @param Email $mail
 * @param string|null $error_message
 * @param array<string, mixed> $args
 */
function pwg_send_mail_test($success, $mail, array $args, $error_message = null): void
{
    global $conf, $user, $lang_info;

    $dir = PHPWG_ROOT_PATH . $conf['data_location'] . 'tmp';
    if (mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        $filename = $dir . '/mail.' . stripslashes((string) $user['username']) . '.' . $lang_info['code'] . '-' . date('YmdHis') . ($success ? '' : '.ERROR');
        if ($args['content_format'] == 'text/plain') {
            $filename .= '.txt';
        } else {
            $filename .= '.html';
        }

        $file = fopen($filename, 'w+');
        if ($file === false) {
            return;
        }
        if (! $success) {
            fwrite($file, 'ERROR: ' . $error_message . "\n\n");
        }
        fwrite($file, $mail->toString());
        fclose($file);
    }
}

/**
 * Generate content mail for reset password
 *
 * Return the content mail to send
 * @since 15
 * @param string $username
 * @param string $password_link
 * @param string $gallery_title
 * @param string $remaining_time
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_reset_password_mail($username, $password_link, $gallery_title, $remaining_time): array
{
    set_make_full_url();

    $message = '<p style="margin: 20px 0">';
    $message = l10n('Someone requested that the password be reset for the following user account:') . ' ' . $username . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('To reset your password, visit the following address:');
    $message .= ' <a href="' . $password_link . '">' . l10n('Change my password') . '</a></p>';
    $message .= '<p style="text-align: center; font-size: 70%;">' . $password_link . '</p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remaining_time);
    $message .= ' ';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

    unset_make_full_url();

    // trigger_change()'s own return type is mixed; fall back to the
    // pre-trigger $message rather than trust a misbehaving handler.
    $message_after_trigger = trigger_change('render_lost_password_mail_content', $message);
    $message = is_string($message_after_trigger) ? $message_after_trigger : $message;

    return [
        'subject' => '[' . $gallery_title . '] ' . l10n('Password Reset'),
        'content' => $message,
        'content_format' => 'text/html',
    ];
}

/**
 * Generate content mail for set password
 *
 * Return the content mail to send
 * @since 15
 * @param string $username
 * @param string $gallery_title
 * @param string $remaining_time
 * @param string $set_password_link
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_set_password_mail($username, $set_password_link, $gallery_title, $remaining_time): array
{
    set_make_full_url();

    $message = '<p style="margin: 20px 0">';
    $message .= l10n('A photo library administrator has created the following account for you:') . ' ' . $username . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('To set your password, visit the following address:');
    $message .= ' <a href="' . $set_password_link . '">' . l10n('Activate') . '</a></p>';
    $message .= '<p style="text-align: center; font-size: 70%; margin: 20px 0;">' . $set_password_link . '</p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remaining_time);
    $message .= ' ';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

    unset_make_full_url();

    // trigger_change()'s own return type is mixed; fall back to the
    // pre-trigger $message rather than trust a misbehaving handler.
    $message_after_trigger = trigger_change('render_lost_password_mail_content', $message);
    $message = is_string($message_after_trigger) ? $message_after_trigger : $message;
    $subject = l10n('Welcome to %s', $gallery_title);

    return [
        'subject' => $subject,
        'content' => $message,
        'content_format' => 'text/html',
    ];
}

/**
 * Generate content mail for user code verification
 *
 * Return the content mail to send
 * @since 16
 * @param string $code
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_code_verification_mail($code): array
{
    global $conf;
    set_make_full_url();
    $message = '<p style="margin: 20px 0">';
    $message .= l10n('Here is your verification code:') . ' <br />';
    $message .= '<span style="font-size: 16px">' . $code . '</span></p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';
    unset_make_full_url();

    $subject = '[' . $conf['gallery_title'] . '] ' . l10n('Your verification code');
    return [
        'subject' => $subject,
        'content' => $message,
        'content_format' => 'text/html',
    ];
}

/**
 * Generate content mail for reset password success
 *
 * Return the content mail to send
 * @since 16
 * @param string $username
 * @param int $nb_of_apikeys
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_success_reset_password_mail($username, $nb_of_apikeys): array
{
    global $conf;
    set_make_full_url();
    $profile_url = get_root_url() . 'profile.php';

    $message = '<p style="margin-top: 20px;">' . l10n('Hello %s,', $username) . '</p>';
    $message .= '<p style="margin-bottom: 20px;">' . l10n('Your password was successfully reset') . '.</p>';
    $message .= '<p>';
    $message .= l10n('If this wasn\'t you, please change your password immediately or contact your webmaster.');
    $message .= '</p>';

    if ($nb_of_apikeys > 0) {
        $message .= '<p style="margin: 20px 0;">';
        $message .= l10n(
            'If you changed your password because you think it was stolen, we recommend revoking your %d API keys <a href="%s">in your profile</a>.',
            $nb_of_apikeys,
            $profile_url
        );
        $message .= '</p>';
    }
    unset_make_full_url();

    $subject = '[' . $conf['gallery_title'] . '] ' . l10n('Your password has been reset');
    return [
        'subject' => $subject,
        'content' => $message,
        'content_format' => 'text/html',
    ];
}

trigger_notify('functions_mail_included');
