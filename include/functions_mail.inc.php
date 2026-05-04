<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\mail
 */
use Pelago\Emogrifier\CssInliner;
use PHPMailer\PHPMailer\PHPMailer;
use Piwigo\Template\Template;

/**
 * Returns the name of the mail sender
 *
 * @return string
 */
function get_mail_sender_name()
{
    return (empty(\Piwigo\Config\Config::mailSenderName()) ? \Piwigo\Config\Config::galleryTitle() : \Piwigo\Config\Config::mailSenderName());
}

/**
 * Returns the email of the mail sender
 *
 * @since 2.6
 * @return string
 */
function get_mail_sender_email()
{
    return (empty(\Piwigo\Config\Config::mailSenderEmail()) ? get_webmaster_mail_address() : \Piwigo\Config\Config::mailSenderEmail());
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
/** @return array<string,mixed> */
function get_mail_configuration(): array
{
    $conf_mail = [
      'send_bcc_mail_webmaster' => \Piwigo\Config\Config::sendBccMailWebmaster(),
      'mail_allow_html' => \Piwigo\Config\Config::mailAllowHtml(),
      'mail_theme' => \Piwigo\Config\Config::mailTheme(),
      'use_smtp' => !empty(\Piwigo\Config\Config::smtpHost()),
      'smtp_host' => \Piwigo\Config\Config::smtpHost(),
      'smtp_user' => \Piwigo\Config\Config::smtpUser(),
      'smtp_password' => \Piwigo\Config\Config::smtpPassword(),
      'smtp_secure' => \Piwigo\Config\Config::smtpSecure(),
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
        $cvt_name = '"'.addcslashes($cvt_name, '"').'"'.' ';
    }

    if (!str_contains($cvt_email, '<')) {
        return $cvt_name.'<'.$cvt_email.'>';
    } else {
        return $cvt_name.$cvt_email;
    }
}

/**
 * Returns the email and the name from a formatted address.
 * @since 2.6
 *
 * @param string|string[] $input - if is an array must contain email[, name]
 * @return array email, name
 */
/** @return array<string,mixed> */
/**
 * @param string|array<mixed> $input
 * @return array<string,mixed>
 */
/**
 * @param string|array<mixed> $input
 * @return array{email: string, name: string}
 */
function unformat_email(string|array $input)
{
    if (is_array($input)) {
        $email = is_scalar($input['email'] ?? null) ? (string) $input['email'] : '';
        $name  = is_scalar($input['name']  ?? null) ? (string) $input['name']  : '';
        return ['email' => $email, 'name' => $name];
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
 * @return string[][]
 */
function get_clean_recipients_list($data): array
{
    if (empty($data)) {
        return [];
    } elseif (is_array($data)) {
        $values = array_values($data);
        if (!is_array($values[0])) {
            $keys = array_keys($data);
            if (is_int($keys[0])) { // simple array of emails
                foreach ($data as &$item) {
                    $item = [
                      'email' => trim(is_scalar($item) ? (string) $item : ''),
                      'name' => '',
                      ];
                }
                unset($item);
            } else { // hashmap of one recipient
                $data = [unformat_email($data)];
            }
        } else { // array of hashmaps
            $data = array_map(fn (mixed $item): array => unformat_email(is_array($item) || is_string($item) ? $item : ''), $data);
        }
    } else {
        $data = explode(',', is_scalar($data) ? (string) $data : '');
        $data = array_map(fn (string $item): array => unformat_email($item), $data);
    }

    $existing = [];
    foreach ($data as $i => $entry) {
        $entry = is_array($entry) ? $entry : [];
        $email = is_scalar($entry['email'] ?? null) ? (string) $entry['email'] : '';
        if (isset($existing[$email])) {
            unset($data[$i]);
        } else {
            $existing[$email] = true;
        }
    }

    /** @var array<array<string>> $result */
    $result = array_values($data);
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
function &get_mail_template(string $email_format): Template
{
    $template = new Template(PHPWG_ROOT_PATH.'themes', 'default', 'template/mail/'.$email_format);
    return $template;
}

/**
 * Return string email format (text/html or text/plain).
 *
 * @param bool $is_html
 */
function get_str_email_format($is_html): string
{
    return ($is_html ? 'text/html' : 'text/plain');
}

/**
 * Switch language to specified language.
 * All entries are push on language stack
 *
 * @param string $language
 */
function switch_lang_to($language): void
{
    $currentLanguage = \Piwigo\Users\CurrentUser::get()->language;

    // Save the current language state on first call
    if (!\Piwigo\Core\LanguageStack::isSwitchInitialized()
        && !\Piwigo\Core\LanguageStack::hasSavedState($currentLanguage)) {
        \Piwigo\Core\LanguageStack::markSwitchInitialized();
        \Piwigo\Core\LanguageStack::saveState($currentLanguage);
    }

    \Piwigo\Core\LanguageStack::pushStack($currentLanguage);
    \Piwigo\Users\CurrentUser::setLanguage($language);

    if (!\Piwigo\Core\LanguageStack::hasSavedState($language)) {
        // Load language from scratch
        \Piwigo\Core\LanguageStack::setLang([]);
        \Piwigo\Core\LanguageStack::setInfo([]);

        load_language('common.lang', '', ['language' => $language]);
        load_language('admin.lang', '', ['language' => $language]);

        $pluginFiles = \Piwigo\Core\LanguageStack::pluginFiles();
        foreach ($pluginFiles as $dirname => $files) {
            foreach ($files as $filename => $options) {
                $options['language'] = $language;
                load_language($filename, $dirname, $options);
            }
        }

        trigger_notify('loading_lang');
        load_language(
            'lang',
            PHPWG_ROOT_PATH.PWG_LOCAL_DIR,
            ['language' => $language, 'no_fallback' => true, 'local' => true]
        );

        \Piwigo\Core\LanguageStack::saveState($language);
    } else {
        \Piwigo\Core\LanguageStack::restoreState($language);
    }
}

/**
 * Switch back language pushed with switch_lang_to() function.
 * @see switch_lang_to()
 * Language files are not reloaded
 */
function switch_lang_back(): void
{
    $language = \Piwigo\Core\LanguageStack::popStack();
    if ($language !== null) {
        \Piwigo\Core\LanguageStack::restoreState($language);
        \Piwigo\Users\CurrentUser::setLanguage($language);
    }
}

/**
 * Send a notification email to all administrators.
 * current user (if admin) is not notified
 *
 * @param string|array $subject
 * @param string|array $content
 * @param boolean $send_technical_details - send user IP and browser
 * @return boolean
 */
/**
 * @param array<mixed>|string $subject
 * @param array<mixed>|string $content
 */
function pwg_mail_notification_admins(array|string $subject, array|string $content, bool $send_technical_details = true, ?int $group_id = null): bool
{
    if (empty($subject) or empty($content)) {
        return false;
    }

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
          'username' => stripslashes(\Piwigo\Users\CurrentUser::get()->username),
          'ip' => $_SERVER['REMOTE_ADDR'],
          'user_agent' => $_SERVER['HTTP_USER_AGENT'],
          ];
    }

    return pwg_mail_admins(
        [
        'subject' => '['. \Piwigo\Config\Config::galleryTitle() .'] '. $subject,
        'mail_title' => \Piwigo\Config\Config::galleryTitle(),
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
 * @param array $tpl - as in pwg_mail()
 * @return boolean
 */
/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_admins(array $args = [], array $tpl = [], bool $exclude_current_user = true, bool $only_webmasters = false, ?int $group_id = null): bool
{
    if (empty($args['content']) and empty($tpl)) {
        return false;
    }

    $return = true;

    $user_statuses = ['webmaster'];
    if (!$only_webmasters) {
        $user_statuses[] = 'admin';
    }

    // get admins (except ourself)
    $query = '
SELECT
    i.user_id,
    u.'.\Piwigo\Config\Config::userFields()['username'].' AS name,
    u.'.\Piwigo\Config\Config::userFields()['email'].' AS email
  FROM '.USERS_TABLE.' AS u
    JOIN '.USER_INFOS_TABLE.' AS i
    ON i.user_id =  u.'.\Piwigo\Config\Config::userFields()['id'];

    if (!is_null($group_id)) {
        $query .= '
    JOIN '.USER_GROUP_TABLE.' AS ug
      ON ug.user_id = i.user_id';
    }

    $query .= '
  WHERE i.status in (\''.implode("','", $user_statuses).'\')
    AND u.'.\Piwigo\Config\Config::userFields()['email'].' IS NOT NULL';

    if (!is_null($group_id)) {
        $query .= '
    AND group_id = '.intval($group_id);
    }

    if ($exclude_current_user) {
        $query .= '
    AND i.user_id <> '.\Piwigo\Users\CurrentUser::get()->id;
    }

    $query .= '
  ORDER BY name
;';
    $admins = query2array($query);

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
 *       o language_selected: filters users of the group by language [default value empty]
 * @param array $tpl - as in pwg_mail()
 * @return boolean
 */
/** @param array<mixed> $tpl */
/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_group(int $group_id, array $args = [], array $tpl = []): bool|int
{
    if (empty($group_id) or (empty($args['content']) and empty($tpl))) {
        return false;
    }

    $return = true;

    // get distinct languages of targeted users
    $query = '
SELECT DISTINCT language
  FROM '.USER_GROUP_TABLE.' AS ug
    INNER JOIN '.USERS_TABLE.' AS u
    ON '.\Piwigo\Config\Config::userFields()['id'].' = ug.user_id
    INNER JOIN '.USER_INFOS_TABLE.' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = '.$group_id.'
    AND '.\Piwigo\Config\Config::userFields()['email'].' <> ""';
    if (!empty($args['language_selected'])) {
        $query .= '
    AND language = \''.(is_scalar($args['language_selected']) ? (string) $args['language_selected'] : '').'\'';
    }

    $query .= '
;';
    $languages = query2array($query, null, 'language');

    if (empty($languages)) {
        return $return;
    }

    foreach ($languages as $language) {
        $language = (string) $language;
        // get subset of users in this group for a specific language
        $query = '
SELECT
    ui.user_id,
    ui.status,
    u.'.\Piwigo\Config\Config::userFields()['username'].' AS name,
    u.'.\Piwigo\Config\Config::userFields()['email'].' AS email
  FROM '.USER_GROUP_TABLE.' AS ug
    INNER JOIN '.USERS_TABLE.' AS u
    ON '.\Piwigo\Config\Config::userFields()['id'].' = ug.user_id
    INNER JOIN '.USER_INFOS_TABLE.' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = '.$group_id.'
    AND '.\Piwigo\Config\Config::userFields()['email'].' <> ""
    AND language = \''.$language.'\'
;';
        $users = query2array($query);

        if (empty($users)) {
            continue;
        }

        switch_lang_to($language);

        foreach ($users as $u) {
            $userId = is_numeric($u['user_id'] ?? null) ? (int) $u['user_id'] : 0;
            $authkey = create_user_auth_key($userId, is_string($u['status'] ?? null) ? $u['status'] : null);

            $user_tpl = $tpl;

            if ($authkey !== false) {
                $authKeyStr = is_scalar($authkey['auth_key'] ?? null) ? (string) $authkey['auth_key'] : '';
                $assign = is_array($user_tpl['assign'] ?? null) ? $user_tpl['assign'] : [];
                $assign['LINK'] = add_url_params(is_string($assign['LINK'] ?? null) ? $assign['LINK'] : '', ['auth' => $authKeyStr]);
                $user_tpl['assign'] = $assign;

                $tpl_assign = is_array($tpl['assign'] ?? null) ? $tpl['assign'] : [];
                $tpl_img = is_array($tpl_assign['IMG'] ?? null) ? $tpl_assign['IMG'] : [];
                if (isset($tpl_img['link'])) {
                    $imgArr = is_array($user_tpl['assign']['IMG'] ?? null) ? $user_tpl['assign']['IMG'] : [];
                    $imgArr['link'] = add_url_params(
                        is_string($tpl_img['link']) ? $tpl_img['link'] : '',
                        ['auth' => $authKeyStr]
                    );
                    $user_tpl['assign']['IMG'] = $imgArr;
                }
            }

            $user_args = $args;
            if ($authkey !== false) {
                $user_args['auth_key'] = $authkey['auth_key'];
            }

            $return &= pwg_mail(is_string($u['email']) ? $u['email'] : '', $user_args, $user_tpl);
        }

        switch_lang_back();
    }

    return $return;
}

/**
 * Sends an email, using Piwigo specific informations.
 *
 * @param string|array $to
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
 *       o mail_title: main title of the mail [default value \Piwigo\Config\Config::galleryTitle()]
 *       o mail_subtitle: subtitle of the mail [default value subject]
 *       o auth_key: authentication key to add on footer link [default value null]
 * @param array $tpl - use these options to define a custom content template file
 *       o filename
 *       o dirname (optional)
 *       o assign (optional)
 *
 * @return boolean
 */
/**
 * @param string|array<mixed> $to
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail(string|array $to, array $args = [], array $tpl = []): bool
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
    $lang_info = \Piwigo\Core\LanguageStack::info();

    if (empty($to) and empty($args['Cc']) and empty($args['Bcc'])) {
        return true;
    }

    $mail = new PHPMailer();

    foreach (get_clean_recipients_list($to) as $recipient) {
        $mail->addAddress($recipient['email'], $recipient['name']);
    }

    $mail->WordWrap = 76;
    $mail->CharSet = 'UTF-8';

    // Compute root_path in order have complete path
    set_make_full_url();

    if (empty($args['from'])) {
        $from = [
          'email' => get_mail_sender_email(),
          'name' => get_mail_sender_name(),
          ];
    } else {
        $from_raw = $args['from'];
        $from = unformat_email(is_array($from_raw) || is_string($from_raw) ? $from_raw : '');
    }
    $mail->setFrom($from['email'], $from['name']);
    $replyEmail = is_string($args['reply_to_mail_address'] ?? null) ? $args['reply_to_mail_address'] : $from['email'];
    $replyName  = is_string($args['reply_to_name']         ?? null) ? $args['reply_to_name']         : $from['name'];
    $mail->addReplyTo($replyEmail, $replyName);

    // Subject
    if (empty($args['subject'])) {
        $args['subject'] = 'Piwigo';
    }
    $args['subject'] = trim((string) preg_replace('#[\n\r]+#s', '', is_scalar($args['subject']) ? (string) $args['subject'] : ''));
    $mail->Subject = $args['subject'];

    // Cc
    if (!empty($args['Cc'])) {
        foreach (get_clean_recipients_list($args['Cc']) as $recipient) {
            $mail->addCC($recipient['email'], $recipient['name']);
        }
    }

    // Bcc
    $Bcc = get_clean_recipients_list($args['Bcc'] ?? '');
    if (\Piwigo\Config\Config::sendBccMailWebmaster()) {
        $Bcc[] = [
          'email' => get_webmaster_mail_address(),
          'name' => '',
          ];
    }
    if (!empty($Bcc)) {
        foreach ($Bcc as $recipient) {
            $mail->addBCC($recipient['email'], $recipient['name']);
        }
    }

    // theme
    if (empty($args['theme']) or !in_array($args['theme'], ['clear','dark'])) {
        $args['theme'] = \Piwigo\Config\Config::mailTheme();
    }

    // content
    if (!isset($args['content'])) {
        $args['content'] = '';
    }

    // try to decompose subject like "[....] ...."
    if (!isset($args['mail_title']) and !isset($args['mail_subtitle'])) {
        if (preg_match('#^\[(.*)\](.*)$#', $args['subject'], $matches)) {
            $args['mail_title'] = $matches[1];
            $args['mail_subtitle'] = $matches[2];
        }
    }
    if (!isset($args['mail_title'])) {
        $args['mail_title'] = \Piwigo\Config\Config::galleryTitle();
    }
    if (!isset($args['mail_subtitle'])) {
        $args['mail_subtitle'] = $args['subject'];
    }

    // content type
    if (empty($args['content_format'])) {
        $args['content_format'] = 'text/plain';
    }

    $content_type_list = [];
    if (\Piwigo\Config\Config::mailAllowHtml() and ($args['email_format'] ?? null) != 'text/plain') {
        $content_type_list[] = 'text/html';
    }
    $content_type_list[] = 'text/plain';

    $contents = [];
    foreach ($content_type_list as $content_type) {
        // key compose of indexes which allow to cache mail data
        $cache_key = $content_type.'-'.(is_scalar($lang_info['code'] ?? null) ? (string) $lang_info['code'] : '');
        if (!empty($args['auth_key'])) {
            $cache_key .= '-'.(is_scalar($args['auth_key']) ? (string) $args['auth_key'] : '');
        }

        if (!\Piwigo\Cache\RequestCache::has('mail_tpl', $cache_key)) {
            $mailTpl = get_mail_template($content_type);
            \Piwigo\Cache\RequestCache::set('mail_tpl', $cache_key, $mailTpl);
            trigger_notify('before_parse_mail_template', $cache_key, $content_type);

            $mailTpl->set_filename('mail_header', 'header.tpl');
            $mailTpl->set_filename('mail_footer', 'footer.tpl');

            $add_url_params = [];
            if (!empty($args['auth_key'])) {
                $add_url_params['auth'] = $args['auth_key'];
            }

            $mailTpl->assign(
                [
                'GALLERY_URL' => add_url_params(get_gallery_home_url(), $add_url_params),
                'GALLERY_TITLE' => $page['gallery_title'] ?? \Piwigo\Config\Config::galleryTitle(),
                'VERSION' => \Piwigo\Config\Config::showVersion() ? PHPWG_VERSION : '',
                'PHPWG_URL' => defined('PHPWG_URL') ? PHPWG_URL : '',
                'CONTENT_ENCODING' => get_pwg_charset(),
                'CONTACT_MAIL' => get_mail_sender_email(),
                ]
            );

            if ($content_type == 'text/html') {
                if ($mailTpl->smarty->templateExists('global-mail-css.tpl')) {
                    $mailTpl->set_filename('global-css', 'global-mail-css.tpl');
                    $mailTpl->assign_var_from_handle('GLOBAL_MAIL_CSS', 'global-css');
                }

                $mailTheme = is_scalar($args['theme']) ? (string) $args['theme'] : '';
                if ($mailTpl->smarty->templateExists('mail-css-'. $mailTheme .'.tpl')) {
                    $mailTpl->set_filename('css', 'mail-css-'. $mailTheme .'.tpl');
                    $mailTpl->assign_var_from_handle('MAIL_CSS', 'css');
                }
            }
        }

        $cachedTpl = \Piwigo\Cache\RequestCache::get('mail_tpl', $cache_key);
        $template = $cachedTpl instanceof Template
            ? $cachedTpl
            : throw new \LogicException('mail template not in cache for key '.$cache_key);
        $template->assign(
            [
            'MAIL_TITLE' => $args['mail_title'],
            'MAIL_SUBTITLE' => $args['mail_subtitle'],
            ]
        );

        // Header
        $contents[$content_type] = $template->parse('mail_header', true) ?? '';

        // Content
        // Stored in a temp variable, if a content template is used it will be assigned
        // to the $CONTENT template variable, otherwise it will be appened to the mail
        $contentStr = is_scalar($args['content']) ? (string) $args['content'] : '';
        if ($args['content_format'] == 'text/plain' and $content_type == 'text/html') {
            // convert plain text to html
            $mail_content =
              '<p>'.
              nl2br(
                  (string) preg_replace(
                      '/(https?:\/\/([-\w\.]+[-\w])+(:\d+)?(\/([\w\/_\.\#-]*(\?\S+)?[^\.\s])?)?)/i',
                      '<a href="$1">$1</a>',
                      htmlspecialchars($contentStr)
                  )
              ).
              '</p>';
        } elseif ($args['content_format'] == 'text/html' and $content_type == 'text/plain') {
            // convert html text to plain text
            $mail_content = strip_tags($contentStr);
        } else {
            $mail_content = $contentStr;
        }

        // Runtime template
        if (isset($tpl['filename'])) {
            if (isset($tpl['dirname'])) {
                $template->set_template_dir((is_scalar($tpl['dirname']) ? (string) $tpl['dirname'] : '') .'/'. $content_type);
            }
            $tplFilename = is_scalar($tpl['filename']) ? (string) $tpl['filename'] : '';
            if ($template->smarty->templateExists($tplFilename .'.tpl')) {
                $template->set_filename($tplFilename, $tplFilename .'.tpl');
                if (!empty($tpl['assign']) && is_array($tpl['assign'])) {
                    $safeAssign = [];
                    foreach ($tpl['assign'] as $k => $v) {
                        if (is_string($k)) {
                            $safeAssign[$k] = $v;
                        }
                    }
                    $template->assign($safeAssign);
                }
                $template->assign('CONTENT', $mail_content);
                $contents[$content_type] .= $template->parse($tplFilename, true) ?? '';
            } else {
                $contents[$content_type] .= $mail_content;
            }
        } else {
            $contents[$content_type] .= $mail_content;
        }

        // Footer
        $contents[$content_type] .= $template->parse('mail_footer', true) ?? '';
    }

    // Undo Compute root_path in order have complete path
    unset_make_full_url();

    // Send content to PHPMailer
    $htmlContent = is_string($contents['text/html'] ?? null) ? $contents['text/html'] : null;
    $plainContent = $contents['text/plain'];
    if ($htmlContent !== null) {
        $mail->isHTML(true);
        $mail->Body = move_css_to_body($htmlContent);

        if ($plainContent !== '') {
            $mail->AltBody = $plainContent;
        }
    } else {
        $mail->isHTML(false);
        $mail->Body = $plainContent;
    }

    $smtpHost = \Piwigo\Config\Config::smtpHost();
    if (!empty($smtpHost)) {
        // now we need to split port number
        if (str_contains($smtpHost, ':')) {
            [$smtp_host, $smtp_port] = explode(':', $smtpHost);
        } else {
            $smtp_host = $smtpHost;
            $smtp_port = 25;
        }

        $mail->IsSMTP();

        // enables SMTP debug information (for testing) 2 - debug, 0 - no message
        $mail->SMTPDebug = 0;

        $mail->Host = $smtp_host;
        $mail->Port = (int) $smtp_port;

        $smtpSecure = \Piwigo\Config\Config::smtpSecure();
        if (!empty($smtpSecure) and in_array($smtpSecure, ['ssl', 'tls'])) {
            $mail->SMTPSecure = $smtpSecure;
        }

        $smtpUser = \Piwigo\Config\Config::smtpUser();
        if (!empty($smtpUser)) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = \Piwigo\Config\Config::smtpPassword();
        }
    }

    $ret = true;
    $pre_result = trigger_change('before_send_mail', true, $to, $args, $mail);

    if ($pre_result) {
        $ret = $mail->send();
        if (!$ret and (!ini_get('display_errors') or is_admin())) {
            trigger_error('Mailer Error: ' . $mail->ErrorInfo, E_USER_WARNING);
        }
        if (\Piwigo\Config\Config::debugMail()) {
            pwg_send_mail_test($ret, $mail, $args);
        }
    }

    return $ret;
}

#[\Deprecated(message: '2.6')]
function pwg_send_mail(mixed $result, string $to, string $subject, string $content, string $headers): bool|int
{
    if (is_admin()) {
        trigger_error('pwg_send_mail function is deprecated', E_USER_NOTICE);
    }

    if (!$result) {
        return pwg_mail($to, [
            'content' => $content,
            'subject' => $subject,
          ]);
    } else {
        return is_bool($result) || is_int($result) ? $result : (bool) $result;
    }
}

/**
 * Moves CSS rules contained in the <style> tag to inline CSS.
 * Used for compatibility with Gmail and such clients
 * @since 2.6
 *
 * @param string $content
 * @return string
 */
function move_css_to_body(string $content): string
{
    try {
        if (empty($content)) {
            return $content;
        }
        return CssInliner::fromHtml($content)->inlineCss()->render();
    } catch (\Throwable) {
        return $content;
    }
}

/**
 * Saves a copy of the mail if _data/tmp.
 *
 * @param boolean $success
 * @param PHPMailer $mail
 */
/** @param array<mixed> $args */
function pwg_send_mail_test(bool $success, mixed $mail, array $args): void
{
    $info = \Piwigo\Core\LanguageStack::info();
    $langCode = is_scalar($info['code'] ?? null) ? (string) $info['code'] : '';

    $dir = PHPWG_ROOT_PATH.\Piwigo\Config\Config::dataLocation().'tmp';
    if (mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
        $filename = $dir.'/mail.'.stripslashes(\Piwigo\Users\CurrentUser::get()->username).'.'.$langCode.'-'.date('YmdHis').($success ? '' : '.ERROR');
        if ($args['content_format'] == 'text/plain') {
            $filename .= '.txt';
        } else {
            $filename .= '.html';
        }

        $file = fopen($filename, 'w+');
        if ($file !== false) {
            if (!$success && $mail instanceof PHPMailer) {
                fwrite($file, 'ERROR: ' . $mail->ErrorInfo . "\n\n");
            }
            if ($mail instanceof PHPMailer) {
                fwrite($file, $mail->getSentMIMEMessage());
            }
            fclose($file);
        }
    }
}

/**
 * Generate content mail for reset password
 *
 * Return the content mail to send
 * @since 15
 * @param string $remaining_time
 * @return array mail content
 */
/** @return array<mixed> */
function pwg_generate_reset_password_mail(string $username, string $password_link, string $gallery_title, string $remaining_time): array
{
    set_make_full_url();

    $message = '<p style="margin: 20px 0">';
    $message = l10n('Someone requested that the password be reset for the following user account:').' '.$username.'</p>';
    $message .= '<p style="margin: 20px 0">'.l10n('To reset your password, visit the following address:');
    $message .= ' <a href="'.$password_link.'">'.l10n('Change my password').'</a></p>';
    $message .= '<p style="text-align: center; font-size: 70%;">'.$password_link.'</p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remaining_time);
    $message .= ' ';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.').'</p>';

    unset_make_full_url();

    $message = trigger_change('render_lost_password_mail_content', $message);

    return [
      'subject' => '['.$gallery_title.'] '.l10n('Password Reset'),
      'content' => $message,
      'content_format' => 'text/html',
      ];
}

/**
 * Generate content mail for set password
 *
 * Return the content mail to send
 * @since 15
 * @param string $gallery_title
 * @param string $remaining_time
 * @return array mail content
 */
/** @return array<mixed> */
function pwg_generate_set_password_mail(string $username, string $set_password_link, string $gallery_title, string $remaining_time): array
{
    set_make_full_url();

    $message = '<p style="margin: 20px 0">';
    $message .= l10n('A photo library administrator has created the following account for you:').' '.$username.'</p>';
    $message .= '<p style="margin: 20px 0">'.l10n('To set your password, visit the following address:');
    $message .= ' <a href="'.$set_password_link.'">'.l10n('Activate').'</a></p>';
    $message .= '<p style="text-align: center; font-size: 70%; margin: 20px 0;">'.$set_password_link.'</p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remaining_time);
    $message .= ' ';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

    unset_make_full_url();

    $message = trigger_change('render_lost_password_mail_content', $message);
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
 * @return array mail content
 */
/** @return array<mixed> */
function pwg_generate_code_verification_mail(string $code): array
{
    set_make_full_url();
    $message = '<p style="margin: 20px 0">';
    $message .= l10n('Here is your verification code:').' <br />';
    $message .= '<span style="font-size: 16px">'. $code .'</span></p>';
    $message .= '<p style="margin: 20px 0;">';
    $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';
    unset_make_full_url();

    $subject = '['.\Piwigo\Config\Config::galleryTitle().'] '.l10n('Your verification code');
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
 * @return array mail content
 */
/** @return array<mixed> */
function pwg_generate_success_reset_password_mail(string $username, int $nb_of_apikeys): array
{
    set_make_full_url();
    $profile_url = get_root_url().'profile.php';

    $message  = '<p style="margin-top: 20px;">'.l10n('Hello %s,', $username).'</p>';
    $message .= '<p style="margin-bottom: 20px;">'.l10n('Your password was successfully reset').'.</p>';
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

    $subject = '['.\Piwigo\Config\Config::galleryTitle().'] '.l10n('Your password has been reset');
    return [
      'subject' => $subject,
      'content' => $message,
      'content_format' => 'text/html',
    ];
}

trigger_notify('functions_mail_included');
