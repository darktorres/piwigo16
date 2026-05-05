<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Doctrine\DBAL\Connection;
use Pelago\Emogrifier\CssInliner;
use PHPMailer\PHPMailer\PHPMailer;
use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Core\LanguageStack;
use Piwigo\Lang\Translator;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;

final readonly class MailService
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function getMailSenderName(): string
    {
        return empty(Config::mailSenderName()) ? Config::galleryTitle() : Config::mailSenderName();
    }

    public function getMailSenderEmail(): string
    {
        return empty(Config::mailSenderEmail()) ? get_webmaster_mail_address() : Config::mailSenderEmail();
    }

    /** @return array<string,mixed> */
    public function getMailConfiguration(): array
    {
        return [
            'send_bcc_mail_webmaster' => Config::sendBccMailWebmaster(),
            'mail_allow_html'         => Config::mailAllowHtml(),
            'mail_theme'              => Config::mailTheme(),
            'use_smtp'                => !empty(Config::smtpHost()),
            'smtp_host'               => Config::smtpHost(),
            'smtp_user'               => Config::smtpUser(),
            'smtp_password'           => Config::smtpPassword(),
            'smtp_secure'             => Config::smtpSecure(),
            'email_webmaster'         => $this->getMailSenderEmail(),
            'name_webmaster'          => $this->getMailSenderName(),
        ];
    }

    public function formatEmail(string $name, string $email): string
    {
        $cvtEmail = trim((string) preg_replace('#[\n\r]+#s', '', $email));
        $cvtName  = trim((string) preg_replace('#[\n\r]+#s', '', $name));

        if ($cvtName != '') {
            $cvtName = '"' . addcslashes($cvtName, '"') . '" ';
        }

        if (!str_contains($cvtEmail, '<')) {
            return $cvtName . '<' . $cvtEmail . '>';
        } else {
            return $cvtName . $cvtEmail;
        }
    }

    /**
     * @param string|array<mixed> $input
     * @return array{email: string, name: string}
     */
    public function unformatEmail(string|array $input): array
    {
        if (is_array($input)) {
            return [
                'email' => is_scalar($input['email'] ?? null) ? (string) $input['email'] : '',
                'name'  => is_scalar($input['name']  ?? null) ? (string) $input['name'] : '',
            ];
        }

        if (preg_match('/(.*)<(.*)>.*/', $input, $matches)) {
            return ['email' => trim($matches[2]), 'name' => trim($matches[1])];
        } else {
            return ['email' => trim($input), 'name' => ''];
        }
    }

    /** @return string[][] */
    public function getCleanRecipientsList(mixed $data): array
    {
        if (empty($data)) {
            return [];
        } elseif (is_array($data)) {
            $values = array_values($data);
            if (!is_array($values[0])) {
                $keys = array_keys($data);
                if (is_int($keys[0])) {
                    foreach ($data as &$item) {
                        $item = ['email' => trim(is_scalar($item) ? (string) $item : ''), 'name' => ''];
                    }
                    unset($item);
                } else {
                    $data = [$this->unformatEmail($data)];
                }
            } else {
                $data = array_map(fn (mixed $item): array => $this->unformatEmail(is_array($item) || is_string($item) ? $item : ''), $data);
            }
        } else {
            $data = explode(',', is_scalar($data) ? (string) $data : '');
            $data = array_map($this->unformatEmail(...), $data);
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

    public function getStrictEmailList(string $emailList): string
    {
        $result = [];
        $list   = explode(',', $emailList);

        foreach ($list as $email) {
            if (str_contains($email, '<')) {
                $email = preg_replace('/.*<(.*)>.*/i', '$1', $email);
            }
            $result[] = trim((string) $email);
        }

        return implode(',', array_unique($result));
    }

    public function getMailTemplate(string $emailFormat): Template
    {
        return new Template(PHPWG_ROOT_PATH . 'themes', 'default', 'template/mail/' . $emailFormat);
    }

    public function getStrEmailFormat(bool $isHtml): string
    {
        return $isHtml ? 'text/html' : 'text/plain';
    }

    public function switchLangTo(string $language): void
    {
        $currentLanguage = CurrentUser::get()->language;

        if (!LanguageStack::isSwitchInitialized() && !LanguageStack::hasSavedState($currentLanguage)) {
            LanguageStack::markSwitchInitialized();
            LanguageStack::saveState($currentLanguage);
            Translator::saveForLanguage($currentLanguage);
        }

        LanguageStack::pushStack($currentLanguage);
        CurrentUser::setLanguage($language);

        if (!LanguageStack::hasSavedState($language)) {
            Translator::pushFresh();
            LanguageStack::setLang([]);
            LanguageStack::setInfo([]);

            load_language('common.lang', '', ['language' => $language]);
            load_language('admin.lang', '', ['language' => $language]);

            $pluginFiles = LanguageStack::pluginFiles();
            foreach ($pluginFiles as $dirname => $files) {
                foreach ($files as $filename => $options) {
                    $options['language'] = $language;
                    load_language($filename, $dirname, $options);
                }
            }

            trigger_notify('loading_lang');
            load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['language' => $language, 'no_fallback' => true, 'local' => true]);

            LanguageStack::saveState($language);
            Translator::saveForLanguage($language);
        } else {
            LanguageStack::restoreState($language);
            Translator::restoreForLanguage($language);
        }
    }

    public function switchLangBack(): void
    {
        $language = LanguageStack::popStack();
        if ($language !== null) {
            LanguageStack::restoreState($language);
            Translator::restoreForLanguage($language);
            CurrentUser::setLanguage($language);
        }
    }

    /**
     * @param array<mixed>|string $subject
     * @param array<mixed>|string $content
     */
    public function pwgMailNotificationAdmins(array|string $subject, array|string $content, bool $sendTechnicalDetails = true, ?int $groupId = null): bool
    {
        if (empty($subject) or empty($content)) {
            return false;
        }

        if (is_array($subject) or is_array($content)) {
            $this->switchLangTo(get_default_language());

            if (is_array($subject)) {
                $subject = l10n_args($subject);
            }
            if (is_array($content)) {
                $content = l10n_args($content);
            }

            $this->switchLangBack();
        }

        $tplVars = [];
        if ($sendTechnicalDetails) {
            $tplVars['TECHNICAL'] = [
                'username'   => stripslashes(CurrentUser::get()->username),
                'ip'         => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            ];
        }

        return $this->pwgMailAdmins(
            [
                'subject'         => '[' . Config::galleryTitle() . '] ' . $subject,
                'mail_title'      => Config::galleryTitle(),
                'mail_subtitle'   => $subject,
                'content'         => $content,
                'content_format'  => 'text/plain',
            ],
            ['filename' => 'notification_admin', 'assign' => $tplVars],
            true,
            false,
            $groupId
        );
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $tpl
     */
    public function pwgMailAdmins(array $args = [], array $tpl = [], bool $excludeCurrentUser = true, bool $onlyWebmasters = false, ?int $groupId = null): bool
    {
        if (empty($args['content']) and empty($tpl)) {
            return false;
        }

        $userStatuses = ['webmaster'];
        if (!$onlyWebmasters) {
            $userStatuses[] = 'admin';
        }

        $query = '
SELECT
    i.user_id,
    u.' . Config::userFields()['username'] . ' AS name,
    u.' . Config::userFields()['email'] . ' AS email
  FROM ' . USERS_TABLE . ' AS u
    JOIN ' . USER_INFOS_TABLE . ' AS i
    ON i.user_id =  u.' . Config::userFields()['id'];

        if (!is_null($groupId)) {
            $query .= '
    JOIN ' . USER_GROUP_TABLE . ' AS ug
      ON ug.user_id = i.user_id';
        }

        $query .= '
  WHERE i.status in (\'' . implode("','", $userStatuses) . '\')
    AND u.' . Config::userFields()['email'] . ' IS NOT NULL';

        if (!is_null($groupId)) {
            $query .= '
    AND group_id = ' . intval($groupId);
        }

        if ($excludeCurrentUser) {
            $query .= '
    AND i.user_id <> ' . CurrentUser::get()->id;
        }

        $query .= '
  ORDER BY name
;';
        $admins = $this->conn->executeQuery($query)->fetchAllAssociative();

        if (empty($admins)) {
            return true;
        }

        $this->switchLangTo(get_default_language());
        $return = $this->pwgMail($admins, $args, $tpl);
        $this->switchLangBack();

        return $return;
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $tpl
     */
    public function pwgMailGroup(int $groupId, array $args = [], array $tpl = []): bool|int
    {
        if (empty($groupId) or (empty($args['content']) and empty($tpl))) {
            return false;
        }

        $return = true;

        $query = '
SELECT DISTINCT language
  FROM ' . USER_GROUP_TABLE . ' AS ug
    INNER JOIN ' . USERS_TABLE . ' AS u
    ON ' . Config::userFields()['id'] . ' = ug.user_id
    INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = ' . $groupId . '
    AND ' . Config::userFields()['email'] . ' <> ""';
        if (!empty($args['language_selected'])) {
            $query .= '
    AND language = \'' . (is_scalar($args['language_selected']) ? (string) $args['language_selected'] : '') . '\'';
        }
        $query .= '
;';
        $languages = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'language');

        if (empty($languages)) {
            return $return;
        }

        foreach ($languages as $language) {
            $language = is_scalar($language) ? (string) $language : '';
            $query    = '
SELECT
    ui.user_id,
    ui.status,
    u.' . Config::userFields()['username'] . ' AS name,
    u.' . Config::userFields()['email'] . ' AS email
  FROM ' . USER_GROUP_TABLE . ' AS ug
    INNER JOIN ' . USERS_TABLE . ' AS u
    ON ' . Config::userFields()['id'] . ' = ug.user_id
    INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
    ON ui.user_id = ug.user_id
  WHERE group_id = ' . $groupId . '
    AND ' . Config::userFields()['email'] . ' <> ""
    AND language = \'' . $language . '\'
;';
            $users = $this->conn->executeQuery($query)->fetchAllAssociative();

            if (empty($users)) {
                continue;
            }

            $this->switchLangTo($language);

            foreach ($users as $u) {
                $userId  = is_numeric($u['user_id'] ?? null) ? (int) $u['user_id'] : 0;
                $authkey = create_user_auth_key($userId, is_string($u['status'] ?? null) ? $u['status'] : null);

                $userTpl = $tpl;

                if ($authkey !== false) {
                    $authKeyStr = is_scalar($authkey['auth_key'] ?? null) ? (string) $authkey['auth_key'] : '';
                    $assign     = is_array($userTpl['assign'] ?? null) ? $userTpl['assign'] : [];
                    $assign['LINK']     = add_url_params(is_string($assign['LINK'] ?? null) ? $assign['LINK'] : '', ['auth' => $authKeyStr]);
                    $userTpl['assign']  = $assign;

                    $tplAssign = is_array($tpl['assign'] ?? null) ? $tpl['assign'] : [];
                    $tplImg    = is_array($tplAssign['IMG'] ?? null) ? $tplAssign['IMG'] : [];
                    if (isset($tplImg['link'])) {
                        $imgArr = is_array($userTpl['assign']['IMG'] ?? null) ? $userTpl['assign']['IMG'] : [];
                        $imgArr['link']        = add_url_params(is_string($tplImg['link']) ? $tplImg['link'] : '', ['auth' => $authKeyStr]);
                        $userTpl['assign']['IMG'] = $imgArr;
                    }
                }

                $userArgs = $args;
                if ($authkey !== false) {
                    $userArgs['auth_key'] = $authkey['auth_key'];
                }

                $return &= $this->pwgMail(is_string($u['email']) ? $u['email'] : '', $userArgs, $userTpl);
            }

            $this->switchLangBack();
        }

        return $return;
    }

    public function mailFunctionIsUsable(): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $smtp = trim((string) ini_get('SMTP'));
            return $smtp !== '' && strtolower($smtp) !== 'localhost';
        }
        return !empty(ini_get('sendmail_path'));
    }

    /**
     * @param string|array<mixed> $to
     * @param array<mixed>        $args
     * @param array<mixed>        $tpl
     */
    public function pwgMail(string|array $to, array $args = [], array $tpl = []): bool
    {
        $page     = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $langInfo = LanguageStack::info();

        if (empty($to) and empty($args['Cc']) and empty($args['Bcc'])) {
            return true;
        }

        $mail = new PHPMailer();

        foreach ($this->getCleanRecipientsList($to) as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name']);
        }

        $mail->WordWrap = 76;
        $mail->CharSet  = 'UTF-8';

        set_make_full_url();

        if (empty($args['from'])) {
            $from = ['email' => $this->getMailSenderEmail(), 'name' => $this->getMailSenderName()];
        } else {
            $fromRaw = $args['from'];
            $from    = $this->unformatEmail(is_array($fromRaw) || is_string($fromRaw) ? $fromRaw : '');
        }
        $mail->setFrom($from['email'], $from['name']);
        $replyEmail = is_string($args['reply_to_mail_address'] ?? null) ? $args['reply_to_mail_address'] : $from['email'];
        $replyName  = is_string($args['reply_to_name']         ?? null) ? $args['reply_to_name'] : $from['name'];
        $mail->addReplyTo($replyEmail, $replyName);

        if (empty($args['subject'])) {
            $args['subject'] = 'Piwigo';
        }
        $args['subject'] = trim((string) preg_replace('#[\n\r]+#s', '', is_scalar($args['subject']) ? (string) $args['subject'] : ''));
        $mail->Subject   = $args['subject'];

        if (!empty($args['Cc'])) {
            foreach ($this->getCleanRecipientsList($args['Cc']) as $recipient) {
                $mail->addCC($recipient['email'], $recipient['name']);
            }
        }

        $Bcc = $this->getCleanRecipientsList($args['Bcc'] ?? '');
        if (Config::sendBccMailWebmaster()) {
            $Bcc[] = ['email' => get_webmaster_mail_address(), 'name' => ''];
        }
        if (!empty($Bcc)) {
            foreach ($Bcc as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name']);
            }
        }

        if (empty($args['theme']) or !in_array($args['theme'], ['clear', 'dark'])) {
            $args['theme'] = Config::mailTheme();
        }

        if (!isset($args['content'])) {
            $args['content'] = '';
        }

        if (!isset($args['mail_title']) and !isset($args['mail_subtitle'])) {
            if (preg_match('#^\[(.*)\](.*)$#', $args['subject'], $matches)) {
                $args['mail_title']    = $matches[1];
                $args['mail_subtitle'] = $matches[2];
            }
        }
        if (!isset($args['mail_title'])) {
            $args['mail_title'] = Config::galleryTitle();
        }
        if (!isset($args['mail_subtitle'])) {
            $args['mail_subtitle'] = $args['subject'];
        }

        if (empty($args['content_format'])) {
            $args['content_format'] = 'text/plain';
        }

        $contentTypeList = [];
        if (Config::mailAllowHtml() and ($args['email_format'] ?? null) != 'text/plain') {
            $contentTypeList[] = 'text/html';
        }
        $contentTypeList[] = 'text/plain';

        $contents = [];
        foreach ($contentTypeList as $contentType) {
            $cacheKey = $contentType . '-' . (is_scalar($langInfo['code'] ?? null) ? (string) $langInfo['code'] : '');
            if (!empty($args['auth_key'])) {
                $cacheKey .= '-' . (is_scalar($args['auth_key']) ? (string) $args['auth_key'] : '');
            }

            if (!RequestCache::has('mail_tpl', $cacheKey)) {
                $mailTpl = $this->getMailTemplate($contentType);
                RequestCache::set('mail_tpl', $cacheKey, $mailTpl);
                trigger_notify('before_parse_mail_template', $cacheKey, $contentType);

                $mailTpl->set_filename('mail_header', 'header.tpl');
                $mailTpl->set_filename('mail_footer', 'footer.tpl');

                $addUrlParams = [];
                if (!empty($args['auth_key'])) {
                    $addUrlParams['auth'] = $args['auth_key'];
                }

                $mailTpl->assign([
                    'GALLERY_URL'      => add_url_params(get_gallery_home_url(), $addUrlParams),
                    'GALLERY_TITLE'    => $page['gallery_title'] ?? Config::galleryTitle(),
                    'VERSION'          => Config::showVersion() ? PHPWG_VERSION : '',
                    'PHPWG_URL'        => defined('PHPWG_URL') ? PHPWG_URL : '',
                    'CONTENT_ENCODING' => get_pwg_charset(),
                    'CONTACT_MAIL'     => $this->getMailSenderEmail(),
                ]);

                if ($contentType == 'text/html') {
                    if ($mailTpl->smarty->templateExists('global-mail-css.tpl')) {
                        $mailTpl->set_filename('global-css', 'global-mail-css.tpl');
                        $mailTpl->assign_var_from_handle('GLOBAL_MAIL_CSS', 'global-css');
                    }

                    $mailTheme = is_scalar($args['theme']) ? (string) $args['theme'] : '';
                    if ($mailTpl->smarty->templateExists('mail-css-' . $mailTheme . '.tpl')) {
                        $mailTpl->set_filename('css', 'mail-css-' . $mailTheme . '.tpl');
                        $mailTpl->assign_var_from_handle('MAIL_CSS', 'css');
                    }
                }
            }

            $cachedTpl = RequestCache::get('mail_tpl', $cacheKey);
            $template  = $cachedTpl instanceof Template
                ? $cachedTpl
                : throw new \LogicException('mail template not in cache for key ' . $cacheKey);
            $template->assign(['MAIL_TITLE' => $args['mail_title'], 'MAIL_SUBTITLE' => $args['mail_subtitle']]);

            $contents[$contentType] = $template->parse('mail_header', true) ?? '';

            $contentStr = is_scalar($args['content']) ? (string) $args['content'] : '';
            if ($args['content_format'] == 'text/plain' and $contentType == 'text/html') {
                $mailContent =
                  '<p>' .
                  nl2br((string) preg_replace(
                      '/(https?:\/\/([-\w\.]+[-\w])+(:\d+)?(\/([\w\/_\.\#-]*(\?\S+)?[^\.\s])?)?)/i',
                      '<a href="$1">$1</a>',
                      htmlspecialchars($contentStr)
                  )) .
                  '</p>';
            } elseif ($args['content_format'] == 'text/html' and $contentType == 'text/plain') {
                $mailContent = strip_tags($contentStr);
            } else {
                $mailContent = $contentStr;
            }

            if (isset($tpl['filename'])) {
                if (isset($tpl['dirname'])) {
                    $template->set_template_dir((is_scalar($tpl['dirname']) ? (string) $tpl['dirname'] : '') . '/' . $contentType);
                }
                $tplFilename = is_scalar($tpl['filename']) ? (string) $tpl['filename'] : '';
                if ($template->smarty->templateExists($tplFilename . '.tpl')) {
                    $template->set_filename($tplFilename, $tplFilename . '.tpl');
                    if (!empty($tpl['assign']) && is_array($tpl['assign'])) {
                        $safeAssign = [];
                        foreach ($tpl['assign'] as $k => $v) {
                            if (is_string($k)) {
                                $safeAssign[$k] = $v;
                            }
                        }
                        $template->assign($safeAssign);
                    }
                    $template->assign('CONTENT', $mailContent);
                    $contents[$contentType] .= $template->parse($tplFilename, true) ?? '';
                } else {
                    $contents[$contentType] .= $mailContent;
                }
            } else {
                $contents[$contentType] .= $mailContent;
            }

            $contents[$contentType] .= $template->parse('mail_footer', true) ?? '';
        }

        unset_make_full_url();

        $htmlContent  = is_string($contents['text/html'] ?? null) ? $contents['text/html'] : null;
        $plainContent = $contents['text/plain'];
        if ($htmlContent !== null) {
            $mail->isHTML(true);
            $mail->Body = $this->moveCssToBody($htmlContent);
            if ($plainContent !== '') {
                $mail->AltBody = $plainContent;
            }
        } else {
            $mail->isHTML(false);
            $mail->Body = $plainContent;
        }

        $smtpHost = Config::smtpHost();
        if (!empty($smtpHost)) {
            if (str_contains($smtpHost, ':')) {
                [$smtpHostStr, $smtpPort] = explode(':', $smtpHost);
            } else {
                $smtpHostStr = $smtpHost;
                $smtpPort    = 25;
            }

            $mail->IsSMTP();
            $mail->SMTPDebug = 0;
            $mail->Host      = $smtpHostStr;
            $mail->Port      = (int) $smtpPort;

            $smtpSecure = Config::smtpSecure();
            if (!empty($smtpSecure) and in_array($smtpSecure, ['ssl', 'tls'])) {
                $mail->SMTPSecure = $smtpSecure;
            }

            $smtpUser = Config::smtpUser();
            if (!empty($smtpUser)) {
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = Config::smtpPassword();
            }
        }

        if (empty($smtpHost) && !$this->mailFunctionIsUsable()) {
            return false;
        }

        $ret        = true;
        $preResult  = trigger_change('before_send_mail', true, $to, $args, $mail);

        if ($preResult) {
            $ret = $mail->send();
            if (!$ret and (!ini_get('display_errors') or is_admin())) {
                trigger_error('Mailer Error: ' . $mail->ErrorInfo, E_USER_WARNING);
            }
            if (Config::debugMail()) {
                $this->pwgSendMailTest($ret, $mail, $args);
            }
        }

        return $ret;
    }

    public function pwgSendMail(mixed $result, string $to, string $subject, string $content, string $headers): bool|int
    {
        if (is_admin()) {
            trigger_error('pwg_send_mail function is deprecated', E_USER_NOTICE);
        }

        if (!$result) {
            return $this->pwgMail($to, ['content' => $content, 'subject' => $subject]);
        } else {
            return is_bool($result) || is_int($result) ? $result : (bool) $result;
        }
    }

    public function moveCssToBody(string $content): string
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

    /** @param array<mixed> $args */
    public function pwgSendMailTest(bool $success, mixed $mail, array $args): void
    {
        $info     = LanguageStack::info();
        $langCode = is_scalar($info['code'] ?? null) ? (string) $info['code'] : '';

        $dir = PHPWG_ROOT_PATH . Config::dataLocation() . 'tmp';
        if (mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            $filename = $dir . '/mail.' . stripslashes(CurrentUser::get()->username) . '.' . $langCode . '-' . date('YmdHis') . ($success ? '' : '.ERROR');
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

    /** @return array<mixed> */
    public function pwgGenerateResetPasswordMail(string $username, string $passwordLink, string $galleryTitle, string $remainingTime): array
    {
        set_make_full_url();

        $message  = '<p style="margin: 20px 0">';
        $message  = l10n('Someone requested that the password be reset for the following user account:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . l10n('To reset your password, visit the following address:');
        $message .= ' <a href="' . $passwordLink . '">' . l10n('Change my password') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%;">' . $passwordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        unset_make_full_url();

        $message = trigger_change('render_lost_password_mail_content', $message);

        return [
            'subject'        => '[' . $galleryTitle . '] ' . l10n('Password Reset'),
            'content'        => $message,
            'content_format' => 'text/html',
        ];
    }

    /** @return array<mixed> */
    public function pwgGenerateSetPasswordMail(string $username, string $setPasswordLink, string $galleryTitle, string $remainingTime): array
    {
        set_make_full_url();

        $message  = '<p style="margin: 20px 0">';
        $message .= l10n('A photo library administrator has created the following account for you:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . l10n('To set your password, visit the following address:');
        $message .= ' <a href="' . $setPasswordLink . '">' . l10n('Activate') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%; margin: 20px 0;">' . $setPasswordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= l10n('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        unset_make_full_url();

        $message = trigger_change('render_lost_password_mail_content', $message);
        $subject = l10n('Welcome to %s', $galleryTitle);

        return [
            'subject'        => $subject,
            'content'        => $message,
            'content_format' => 'text/html',
        ];
    }

    /** @return array<mixed> */
    public function pwgGenerateCodeVerificationMail(string $code): array
    {
        set_make_full_url();
        $message  = '<p style="margin: 20px 0">';
        $message .= l10n('Here is your verification code:') . ' <br />';
        $message .= '<span style="font-size: 16px">' . $code . '</span></p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= l10n('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';
        unset_make_full_url();

        $subject = '[' . Config::galleryTitle() . '] ' . l10n('Your verification code');
        return [
            'subject'        => $subject,
            'content'        => $message,
            'content_format' => 'text/html',
        ];
    }

    /** @return array<mixed> */
    public function pwgGenerateSuccessResetPasswordMail(string $username, int $nbOfApikeys): array
    {
        set_make_full_url();
        $profileUrl = get_root_url() . 'profile.php';

        $message  = '<p style="margin-top: 20px;">' . l10n('Hello %s,', $username) . '</p>';
        $message .= '<p style="margin-bottom: 20px;">' . l10n('Your password was successfully reset') . '.</p>';
        $message .= '<p>';
        $message .= l10n('If this wasn\'t you, please change your password immediately or contact your webmaster.');
        $message .= '</p>';

        if ($nbOfApikeys > 0) {
            $message .= '<p style="margin: 20px 0;">';
            $message .= l10n('If you changed your password because you think it was stolen, we recommend revoking your %d API keys <a href="%s">in your profile</a>.', $nbOfApikeys, $profileUrl);
            $message .= '</p>';
        }
        unset_make_full_url();

        $subject = '[' . Config::galleryTitle() . '] ' . l10n('Your password has been reset');
        return [
            'subject'        => $subject,
            'content'        => $message,
            'content_format' => 'text/html',
        ];
    }
}
