<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email composition and sending (Symfony Mailer + Emogrifier CSS-inlining).
 * Infrastructure only -- no domain-specific logic of its own -- must build
 * before User/Comment, which both send mail through here.
 *
 * Reads mail-related settings from `Piwigo\Config\Config`'s typed
 * accessors. Piwigo\Config\ConfigDb::loadConfFromDb() (called from
 * common.inc.php on every real request) now syncs every DB-persisted
 * config row into both the legacy `$conf` global AND `CurrentConfig::$data`
 * (Legacy Coupling Retirement Track A batch A4 -- previously only the
 * former was updated, so a MailService built on CurrentConfig:: accessors would
 * have silently ignored every real admin-configured mail setting
 * (debug_mail, smtp_host, mail_sender_name, ...), always falling back to
 * install-time defaults).
 *
 * Injects only the optional WebmasterMailProviderInterface test seam (P23
 * batch 8f-4, see the constructor's own docblock) -- remaining cross-domain
 * calls (l10n(), trigger_notify()/trigger_change()) stay as plain
 * global-function calls to the settled composer-autoloaded procedural
 * helpers, matching every other P17/P18-era service; Url-family calls go
 * through the private urlService() helper below (Legacy Coupling
 * Retirement Phase 4c, see its own docblock). l10n_args()/load_language()
 * calls above were retargeted to Piwigo\Core\Lang::args()/::load() in
 * P23 batch 8d -- l10n() itself stays a bare call (track-2 relocated,
 * too widely used to retarget, see src/Piwigo/Lang/functions.php).
 *
 * The template-render cache and language-switch stack (`$conf_mail`/
 * `$switch_lang` in the procedural version) are request-scoped state with no
 * other reader in the codebase (confirmed via grep) -- kept as private
 * static state on this class instead of raw globals, with a reset() for
 * test isolation, matching StorageRegistry/SessionService/PageState's own
 * established self-managed-state pattern.
 *
 * Implements `Piwigo\Core\MailerInterface` (P23 batch 8c) so
 * L2aCoreDomain/L2bExtendedDomain classes that may not depend on this
 * class directly (this file is L3Presentation) can constructor-inject the
 * interface instead — `Users\UserService`/`Comment\CommentService`, bound
 * via `config/container.php`.
 */
final class MailService implements MailerInterface
{
    /**
     * P23 batch 8f-4: replaces the 2 deliberately-bare
     * get_webmaster_mail_address() calls (free function deleted with
     * include/functions.inc.php). Optional-with-lazy-default rather than
     * required: this class has ~98 `new MailService()` construction sites
     * and the dependency is only reached on the sender-fallback/
     * Bcc-webmaster paths -- production sites keep constructing with no
     * args and get the real Piwigo\Users\UserRepository (a legal L3->L2a
     * downward dep, constructed lazily so no DB connection is built for
     * the many code paths that never need it); unit tests
     * (MailServiceTest/SendNotificationEmailHandlerTest) pass a fake
     * implementation instead of the old global-function-stub shadowing.
     */
    public function __construct(
        private readonly ?WebmasterMailProviderInterface $webmasterMailProvider = null,
        private readonly ?MailRecipientRepository $mailRecipientRepo = null,
        private readonly ?\Piwigo\Auth\AuthService $authService = null,
    ) {}

    private function webmasterMailAddress(): string
    {
        $provider = $this->webmasterMailProvider
            ?? new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build());

        return $provider->getWebmasterMailAddress();
    }

    /**
     * Optional-with-lazy-default, same reasoning as
     * $webmasterMailProvider above -- ~98 `new MailService()` construction
     * sites, most of which never reach mailAdmins()/mailGroup().
     */
    private function recipientRepo(): MailRecipientRepository
    {
        return $this->mailRecipientRepo
            ?? new MailRecipientRepository(\Piwigo\Db\DbConnection::build());
    }

    /**
     * Optional-with-lazy-default, same reasoning as
     * $webmasterMailProvider above -- ~98 `new MailService()` construction
     * sites, only mailGroup() reaches this. Unlike UserService (which
     * genuinely can't be a constructor dependency here -- UserService
     * constructor-depends on MailerInterface, i.e. this class, a real
     * cycle), AuthService doesn't depend back on MailerInterface, so this
     * is just the usual high-caller-count lazy-default, not a circular-
     * dependency workaround.
     */
    private function authService(): \Piwigo\Auth\AuthService
    {
        return $this->authService
            ?? new \Piwigo\Auth\AuthService(
                new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()),
                new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())),
                new HtmlService(),
                new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())),
                new \Piwigo\Auth\CookieService(),
            );
    }

    /**
     * Throwaway construction, not a constructor property -- this class
     * has ~98 `new MailService()` construction sites, several of them
     * inside Piwigo\Bootstrap\RedirectService's own early-crash fallback
     * chain (RedirectService -> UserService -> MailService, all literal
     * `new` calls). PHP-DI's reflection-based autowiring only ever
     * inspects class constructors, never ordinary methods, so a private
     * helper method is safe from re-closing that chain even though an
     * optional/nullable constructor property of the same type would not
     * be (PHP-DI may still attempt to autowire an optional typed
     * constructor param). Legacy Coupling Retirement Phase 4c.
     */
    private function urlService(): UrlServiceInterface
    {
        return new UrlService(new HtmlService());
    }

    /**
     * DRY extraction, not a constructor dependency: unlike authService()
     * above, UserService's own constructor takes MailerInterface (this
     * class), so an optional-default constructor param the way
     * $authService is would create a real eager-construction cycle the
     * moment both sides used their own zero-arg default. A private method
     * building a fresh instance keeps the exact same behavior every call
     * site already had (a fresh `new self()` passed as UserService's own
     * $mailer, not $this) -- was repeated verbatim at 2 call sites (Phase
     * 1k DI-chain audit).
     */
    private function userService(): \Piwigo\Users\UserService
    {
        return new \Piwigo\Users\UserService(
            new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()),
            new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()),
            new self(),
            new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())),
            new HtmlService(),
            \Piwigo\Db\DbConnection::build(),
        );
    }

    /**
     * @var array<string, array{theme: Template}>
     */
    private static array $templateCache = [];

    private static bool $switchLangInitialised = false;

    /**
     * @var list<string>
     */
    private static array $switchLangStack = [];

    /**
     * @var array<string, array{lang_info: array<string, mixed>, lang: array<string, string|array<int, string>>}>
     */
    private static array $switchLangLanguages = [];

    public static function reset(): void
    {
        self::$templateCache = [];
        self::$switchLangInitialised = false;
        self::$switchLangStack = [];
        self::$switchLangLanguages = [];
    }

    /**
     * Matches empty()'s exact truthiness semantics as a real strict
     * comparison (PHPStan forbids empty() itself) -- used throughout this
     * class's $args/$tpl array shapes, whose values are declared `mixed`
     * (caller-supplied, optional keys).
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false
            || $value === 0 || $value === 0.0 || $value === '0';
    }

    /**
     * @return array{id: string, username: string, password: string, email: string}
     */
    private static function userFields(): array
    {
        return \Piwigo\Config\CurrentConfig::userFields();
    }

    public function getMailSenderName(): string
    {
        $senderName = \Piwigo\Config\CurrentConfig::mailSenderName();
        if ($senderName !== '') {
            return $senderName;
        }

        $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();

        return $galleryTitle;
    }

    public function getMailSenderEmail(): string
    {
        $senderEmail = \Piwigo\Config\CurrentConfig::mailSenderEmail();
        if ($senderEmail !== '') {
            return $senderEmail;
        }

        return $this->webmasterMailAddress();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMailConfiguration(): array
    {
        $smtpHost = \Piwigo\Config\CurrentConfig::smtpHost();

        return [
            'send_bcc_mail_webmaster' => \Piwigo\Config\CurrentConfig::sendBccMailWebmaster(),
            'mail_allow_html' => \Piwigo\Config\CurrentConfig::mailAllowHtml(),
            'mail_theme' => \Piwigo\Config\CurrentConfig::mailTheme(),
            'use_smtp' => $smtpHost !== '',
            'smtp_host' => $smtpHost,
            'smtp_user' => \Piwigo\Config\CurrentConfig::smtpUser(),
            'smtp_password' => \Piwigo\Config\CurrentConfig::smtpPassword(),
            'smtp_secure' => is_string(\Piwigo\Config\CurrentConfig::smtpSecure() ?? null) ? \Piwigo\Config\CurrentConfig::smtpSecure() : null,
            'email_webmaster' => $this->getMailSenderEmail(),
            'name_webmaster' => $this->getMailSenderName(),
        ];
    }

    /**
     * Returns an email address with an associated real name. Either
     * "email@domain.com" or "name <email@domain.com>".
     */
    public function formatEmail(string $name, string $email): string
    {
        $cvtEmail = trim((string) preg_replace('#[\n\r]+#s', '', $email));
        $cvtName = trim((string) preg_replace('#[\n\r]+#s', '', $name));

        if ($cvtName !== '') {
            $cvtName = '"' . addcslashes($cvtName, '"') . '" ';
        }

        if (! str_contains($cvtEmail, '<')) {
            return $cvtName . '<' . $cvtEmail . '>';
        }

        return $cvtName . $cvtEmail;
    }

    /**
     * Returns the email and the name from a formatted address.
     *
     * @param string|array<int|string, mixed> $input if an array, must contain email[, name]
     * @return array{email: string, name: string}
     */
    public function unformatEmail(string|array $input): array
    {
        if (is_array($input)) {
            if (! isset($input['email']) || ! is_string($input['email'])) {
                throw new \InvalidArgumentException(__METHOD__ . '(): array input must contain a string "email" key');
            }

            return [
                'email' => $input['email'],
                'name' => isset($input['name']) && is_string($input['name']) ? $input['name'] : '',
            ];
        }

        if (preg_match('/(.*)<(.*)>.*/', $input, $matches) === 1) {
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
     * Returns a clean array of hashmaps (email, name), removing duplicates.
     * Accepts a comma-separated list, an array of emails, a single hashmap
     * (email[, name]), or an array of incomplete hashmaps.
     *
     * @return list<array{email: string, name: string}>
     */
    public function getCleanRecipientsList(mixed $data): array
    {
        if ($data === null || $data === '' || $data === [] || $data === false || $data === 0) {
            return [];
        }

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
                    /** @var array<int|string, mixed> $data */
                    $entries[] = $this->unformatEmail($data);
                }
            } else { // array of hashmaps
                foreach ($data as $item) {
                    if (is_array($item) || is_string($item)) {
                        $entries[] = $this->unformatEmail($item);
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
                $entries[] = $this->unformatEmail($item);
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
     */
    public function getStrictEmailList(string $emailList): string
    {
        $result = [];
        $list = explode(',', $emailList);

        foreach ($list as $email) {
            if (str_contains($email, '<')) {
                $email = preg_replace('/.*<(.*)>.*/i', '$1', $email);
            }
            $result[] = trim((string) $email);
        }

        return implode(',', array_unique($result));
    }

    /**
     * Returns a new mail template. $emailFormat is 'text/html' or 'text/plain'.
     */
    public function getMailTemplate(string $emailFormat): Template
    {
        return new Template(CurrentPaths::get()->root . 'themes', 'default', 'template/mail/' . $emailFormat);
    }

    public function getStrEmailFormat(bool $isHtml): string
    {
        return $isHtml ? 'text/html' : 'text/plain';
    }

    /**
     * Switches language to the given one, pushing the current one onto a
     * LIFO stack.
     */
    public function switchLangTo(string $language): void
    {
        $currentUserLanguage = CurrentUser::get()->language;

        // Language of the current user is saved (considered OK on first call).
        if (! self::$switchLangInitialised && ! isset(self::$switchLangLanguages[$currentUserLanguage])) {
            self::$switchLangInitialised = true;
            self::$switchLangLanguages[$currentUserLanguage] = [
                'lang_info' => Lang::langInfo(),
                'lang' => Lang::snapshot(),
            ];
        }

        self::$switchLangStack[] = $currentUserLanguage;
        CurrentUser::updateLanguage($language);

        if (! isset(self::$switchLangLanguages[$language])) {
            // Re-init language arrays.
            Lang::setLangInfo([]);
            Lang::restore(null);

            Lang::load('common.lang', '', [
                'language' => $language,
            ]);
            // No test admin because script is checked admin (user selected no).
            // Translations are in admin file too.
            Lang::load('admin.lang', '', [
                'language' => $language,
            ]);

            // Reload all plugin files (see Lang::load()'s own docblock).
            foreach (Lang::languageFiles() as $dirname => $files) {
                foreach ($files as $filename => $options) {
                    $options['language'] = $language;
                    Lang::load($filename, $dirname, $options);
                }
            }

            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loading_lang');
            Lang::load(
                'lang',
                CurrentPaths::get()->siteLocal,
                [
                    'language' => $language,
                    'no_fallback' => true,
                    'local' => true,
                ]
            );

            self::$switchLangLanguages[$language] = [
                'lang_info' => Lang::langInfo(),
                'lang' => Lang::snapshot(),
            ];
        } else {
            $entry = self::$switchLangLanguages[$language];
            Lang::setLangInfo($entry['lang_info']);
            Lang::restore($entry['lang']);
        }
    }

    /**
     * Switches back to the language pushed with switchLangTo(). Language
     * files are not reloaded.
     */
    public function switchLangBack(): void
    {
        if (self::$switchLangStack === []) {
            return;
        }

        $language = array_pop(self::$switchLangStack);

        if (isset(self::$switchLangLanguages[$language])) {
            $entry = self::$switchLangLanguages[$language];
            Lang::setLangInfo($entry['lang_info']);
            Lang::restore($entry['lang']);
        }
        CurrentUser::updateLanguage($language);
    }

    /**
     * Sends a notification email to all administrators. The current user
     * (if admin) is not notified.
     *
     * @param string|array<int|string, mixed> $subject
     * @param string|array<int|string, mixed> $content
     * @param bool $sendTechnicalDetails send user IP and browser
     */
    #[\Override]
    public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool
    {
        if ($subject === '' || $subject === [] || $content === '' || $content === []) {
            return false;
        }

        if (is_array($subject) || is_array($content)) {
            $this->switchLangTo($this->userService()->getDefaultLanguage());

            if (is_array($subject)) {
                $subject = Lang::args($subject);
            }
            if (is_array($content)) {
                $content = Lang::args($content);
            }

            $this->switchLangBack();
        }

        $tplVars = [];
        if ($sendTechnicalDetails) {
            $username = \Piwigo\Users\CurrentUser::get()->username;
            $tplVars['TECHNICAL'] = [
                'username' => stripslashes($username),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ];
        }

        $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();

        return $this->mailAdmins(
            [
                'subject' => '[' . $galleryTitle . '] ' . $subject,
                'mail_title' => $galleryTitle,
                'mail_subtitle' => $subject,
                'content' => $content,
                'content_format' => 'text/plain',
            ],
            [
                'filename' => 'notification_admin',
                'assign' => $tplVars,
            ],
            true, // excludeCurrentUser
            false, // onlyWebmasters
            $groupId
        );
    }

    /**
     * Sends an email to all administrators. The current user (if admin) is
     * excluded.
     *
     * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args as in mail()
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl as in mail()
     */
    public function mailAdmins(array $args = [], array $tpl = [], bool $excludeCurrentUser = true, bool $onlyWebmasters = false, int|string|null $groupId = null): bool
    {
        if ((! isset($args['content']) || self::emptyValue($args['content'])) && $tpl === []) {
            return false;
        }

        $userStatuses = ['webmaster'];
        if (! $onlyWebmasters) {
            $userStatuses[] = 'admin';
        }

        $userFields = self::userFields();

        $admins = $this->recipientRepo()
            ->findAdminsAndWebmasters(
                $userFields['id'],
                $userFields['username'],
                $userFields['email'],
                $userStatuses,
                $groupId !== null ? (int) $groupId : null,
                $excludeCurrentUser ? \Piwigo\Users\CurrentUser::get()->id : null,
            );

        if ($admins === []) {
            return true;
        }

        // mail()'s own $to parameter is a deliberately dynamic, many-shapes
        // contract (used by every other caller too, see
        // getCleanRecipientsList()'s own docblock) -- converted back to
        // array form here, at this one boundary, rather than widening that
        // shared contract to also understand a real MailRecipient object.
        $adminRows = array_map(static fn (\Piwigo\Mail\Projection\MailRecipient $r): array => $r->toArray(), $admins);

        $this->switchLangTo($this->userService()->getDefaultLanguage());
        $return = $this->mail($adminRows, $args, $tpl);
        $this->switchLangBack();

        return $return;
    }

    /**
     * Sends an email to a group.
     *
     * @param array{language_selected?: string, from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args as in mail() -- language_selected filters users of the group by language
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl as in mail()
     */
    public function mailGroup(int $groupId, array $args = [], array $tpl = []): bool
    {
        if ($groupId === 0 || ((! isset($args['content']) || self::emptyValue($args['content'])) && $tpl === [])) {
            return false;
        }

        $userFields = self::userFields();
        $return = true;

        $languageSelected = isset($args['language_selected']) && ! self::emptyValue($args['language_selected'])
            ? $args['language_selected']
            : null;

        $languages = $this->recipientRepo()
            ->findDistinctLanguagesInGroup(
                $userFields['id'],
                $userFields['email'],
                $groupId,
                $languageSelected,
            );

        if ($languages === []) {
            return $return;
        }

        foreach ($languages as $language) {
            if ($language === '') {
                continue;
            }

            $users = $this->recipientRepo()
                ->findByGroupAndLanguage(
                    $userFields['id'],
                    $userFields['username'],
                    $userFields['email'],
                    $groupId,
                    $language,
                );

            if ($users === []) {
                continue;
            }

            $this->switchLangTo($language);

            foreach ($users as $u) {
                $uEmail = $u->email;

                $authkey = $this->authService()
                    ->createUserAuthKey($u->userId, $u->status);

                $userTpl = $tpl;

                if ($authkey !== false) {
                    $link = $tpl['assign']['LINK'] ?? null;
                    $userTpl['assign']['LINK'] = $this->urlService()->addUrlParams(is_string($link) ? $link : '', [
                        'auth' => $authkey['auth_key'] ?? null,
                    ]);

                    $img = $userTpl['assign']['IMG'] ?? null;
                    if (is_array($img) && isset($img['link']) && is_string($img['link'])) {
                        $img['link'] = $this->urlService()->addUrlParams(
                            $img['link'],
                            [
                                'auth' => $authkey['auth_key'] ?? null,
                            ]
                        );
                        $userTpl['assign']['IMG'] = $img;
                    }
                }

                $userArgs = $args;
                // language_selected is this method's own filtering option
                // (already consumed above to build the SQL query); mail()
                // doesn't accept it.
                unset($userArgs['language_selected']);
                if ($authkey !== false) {
                    $authKey = $authkey['auth_key'] ?? null;
                    if (is_string($authKey)) {
                        $userArgs['auth_key'] = $authKey;
                    }
                }

                $return = $this->mail($uEmail, $userArgs, $userTpl) && $return;
            }

            $this->switchLangBack();
        }

        return $return;
    }

    /**
     * Sends an email, using Piwigo-specific information.
     *
     * @param string|array<int|string, mixed> $to
     * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     *        from: sender [default value webmaster email]
     *        reply_to_mail_address/reply_to_name: reply-to can differ from "from"
     *        Cc/Bcc: carbon-copy/blind-carbon-copy receivers
     *        subject [default 'Piwigo']
     *        content: content of the mail [default '']
     *        content_format: format of the mail content [default 'text/plain']
     *        email_format: global mail format
     *        theme: theme to use
     *        mail_title/mail_subtitle: header title/subtitle
     *        auth_key: authentication key to add on the footer link
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl custom content template
     */
    #[\Override]
    public function mail(string|array $to, array $args = [], array $tpl = []): bool
    {
        if (self::emptyValue($to) && (! isset($args['Cc']) || self::emptyValue($args['Cc'])) && (! isset($args['Bcc']) || self::emptyValue($args['Bcc']))) {
            return true;
        }

        $confMail = $this->getMailConfiguration();

        $email = new Email();

        foreach ($this->getCleanRecipientsList($to) as $recipient) {
            $email->addTo(new Address($recipient['email'], $recipient['name']));
        }

        // Compute root_path in order to have a complete path.
        $this->urlService()
            ->setMakeFullUrl();

        if (! isset($args['from']) || self::emptyValue($args['from'])) {
            $from = [
                'email' => is_string($confMail['email_webmaster']) ? $confMail['email_webmaster'] : '',
                'name' => is_string($confMail['name_webmaster']) ? $confMail['name_webmaster'] : '',
            ];
        } else {
            $fromInput = $args['from'];
            if (! is_array($fromInput) && ! is_string($fromInput)) {
                $fromInput = is_scalar($fromInput) ? (string) $fromInput : '';
            }
            $from = $this->unformatEmail($fromInput);
        }
        $email->from(new Address($from['email'], $from['name']));
        $replyToMail = $args['reply_to_mail_address'] ?? $from['email'];
        $replyToName = $args['reply_to_name'] ?? $from['name'];
        $email->replyTo(new Address($replyToMail, $replyToName));

        // Subject.
        if (! isset($args['subject']) || self::emptyValue($args['subject'])) {
            $args['subject'] = 'Piwigo';
        }
        $subjectInput = is_scalar($args['subject']) ? (string) $args['subject'] : '';
        $args['subject'] = trim((string) preg_replace('#[\n\r]+#s', '', $subjectInput));
        $email->subject($args['subject']);

        // Cc.
        if (isset($args['Cc']) && ! self::emptyValue($args['Cc'])) {
            foreach ($this->getCleanRecipientsList($args['Cc']) as $recipient) {
                $email->addCc(new Address($recipient['email'], $recipient['name']));
            }
        }

        // Bcc.
        $bcc = $this->getCleanRecipientsList($args['Bcc'] ?? null);
        if ($confMail['send_bcc_mail_webmaster'] === true) {
            $bcc[] = [
                'email' => $this->webmasterMailAddress(),
                'name' => '',
            ];
        }
        foreach ($bcc as $recipient) {
            $email->addBcc(new Address($recipient['email'], $recipient['name']));
        }

        // Theme.
        if (! isset($args['theme']) || self::emptyValue($args['theme']) || ! in_array($args['theme'], ['clear', 'dark'], true)) {
            $args['theme'] = is_string($confMail['mail_theme']) ? $confMail['mail_theme'] : 'clear';
        }

        // Content.
        if (! isset($args['content'])) {
            $args['content'] = '';
        }

        // Try to decompose subject like "[....] ....".
        if (! isset($args['mail_title']) && ! isset($args['mail_subtitle'])) {
            if (preg_match('#^\[(.*)\](.*)$#', $args['subject'], $matches) === 1) {
                $args['mail_title'] = $matches[1];
                $args['mail_subtitle'] = $matches[2];
            }
        }
        if (! isset($args['mail_title'])) {
            $args['mail_title'] = \Piwigo\Config\CurrentConfig::galleryTitle();
        }
        if (! isset($args['mail_subtitle'])) {
            $args['mail_subtitle'] = $args['subject'];
        }

        // Content type.
        if (! isset($args['content_format']) || self::emptyValue($args['content_format'])) {
            $args['content_format'] = 'text/plain';
        }

        $contentTypeList = [];
        if ($confMail['mail_allow_html'] === true && ($args['email_format'] ?? null) !== 'text/plain') {
            $contentTypeList[] = 'text/html';
        }
        $contentTypeList[] = 'text/plain';

        $langCode = Lang::langInfo()['code'] ?? null;
        $langCode = is_string($langCode) ? $langCode : '';

        $contents = [];
        foreach ($contentTypeList as $contentType) {
            // Key composed of indexes which allow caching mail data.
            $cacheKey = $contentType . '-' . $langCode;
            if (isset($args['auth_key']) && ! self::emptyValue($args['auth_key'])) {
                $cacheKey .= '-' . $args['auth_key'];
            }

            if (! isset(self::$templateCache[$cacheKey])) {
                $template = $this->getMailTemplate($contentType);
                self::$templateCache[$cacheKey] = [
                    'theme' => $template,
                ];
                \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('before_parse_mail_template', $cacheKey, $contentType);

                $template->set_filename('mail_header', 'header.tpl');
                $template->set_filename('mail_footer', 'footer.tpl');

                $addUrlParams = [];
                if (isset($args['auth_key']) && ! self::emptyValue($args['auth_key'])) {
                    $addUrlParams['auth'] = $args['auth_key'];
                }

                $galleryHomeUrl = $this->urlService()
                    ->getGalleryHomeUrl();
                $galleryHomeUrl = is_string($galleryHomeUrl) ? $galleryHomeUrl : '';

                $template->assign(
                    [
                        'GALLERY_URL' => $this->urlService()
                            ->addUrlParams($galleryHomeUrl, $addUrlParams),
                        'GALLERY_TITLE' => \Piwigo\Config\CurrentConfig::galleryTitle(),
                        'VERSION' => \Piwigo\Config\CurrentConfig::showVersion() ? AppInfo::VERSION : '',
                        'PHPWG_URL' => AppInfo::URL,
                        'CONTENT_ENCODING' => \Piwigo\Core\CharsetHelper::getPwgCharset(),
                        'CONTACT_MAIL' => $confMail['email_webmaster'],
                    ]
                );

                if ($contentType === 'text/html') {
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

            $template = self::$templateCache[$cacheKey]['theme'];
            $template->assign(
                [
                    'MAIL_TITLE' => $args['mail_title'],
                    'MAIL_SUBTITLE' => $args['mail_subtitle'],
                ]
            );

            // Header.
            $contents[$contentType] = $template->parse('mail_header', true);

            // Content -- stored in a temp variable; if a content template is
            // used it's assigned to CONTENT, otherwise appended to the mail.
            $contentInput = is_scalar($args['content']) ? (string) $args['content'] : '';

            if ($args['content_format'] === 'text/plain' && $contentType === 'text/html') {
                // Convert plain text to HTML.
                $mailContent =
                    '<p>' .
                    nl2br(
                        (string) preg_replace(
                            '/(https?:\/\/([-\w\.]+[-\w])+(:\d+)?(\/([\w\/_\.\#-]*(\?\S+)?[^\.\s])?)?)/i',
                            '<a href="$1">$1</a>',
                            htmlspecialchars($contentInput)
                        )
                    ) .
                    '</p>';
            } elseif ($args['content_format'] === 'text/html' && $contentType === 'text/plain') {
                // Convert HTML text to plain text.
                $mailContent = strip_tags($contentInput);
            } else {
                $mailContent = $contentInput;
            }

            // Runtime template.
            if (isset($tpl['filename'])) {
                if (isset($tpl['dirname'])) {
                    $template->set_template_dir($tpl['dirname'] . '/' . $contentType);
                }
                if ($template->smarty->templateExists($tpl['filename'] . '.tpl')) {
                    $template->set_filename($tpl['filename'], $tpl['filename'] . '.tpl');
                    if (isset($tpl['assign']) && ! self::emptyValue($tpl['assign'])) {
                        $template->assign($tpl['assign']);
                    }
                    $template->assign('CONTENT', $mailContent);
                    $contents[$contentType] .= $template->parse($tpl['filename'], true);
                } else {
                    $contents[$contentType] .= $mailContent;
                }
            } else {
                $contents[$contentType] .= $mailContent;
            }

            // Footer.
            $contents[$contentType] .= $template->parse('mail_footer', true);
        }

        // Undo compute-root_path.
        $this->urlService()
            ->unsetMakeFullUrl();

        // Send content. 'text/plain' is always present in $contents
        // (unconditionally in $contentTypeList above); 'text/html' is
        // conditional.
        if (isset($contents['text/html'])) {
            $email->html($this->moveCssToBody($contents['text/html']));
        }
        $email->text($contents['text/plain']);

        if ($confMail['use_smtp'] === true) {
            $smtpHostRaw = is_string($confMail['smtp_host']) ? $confMail['smtp_host'] : '';

            // Now split the port number.
            if (str_contains($smtpHostRaw, ':')) {
                [$smtpHost, $smtpPort] = explode(':', $smtpHostRaw);
            } else {
                $smtpHost = $smtpHostRaw;
                $smtpPort = '25';
            }

            $dsnAuth = '';
            if (isset($confMail['smtp_user']) && ! self::emptyValue($confMail['smtp_user'])) {
                $smtpUser = is_string($confMail['smtp_user']) ? $confMail['smtp_user'] : '';
                $smtpPassword = is_string($confMail['smtp_password']) ? $confMail['smtp_password'] : '';
                $dsnAuth = rawurlencode($smtpUser) . ':' . rawurlencode($smtpPassword) . '@';
            }

            $dsn = 'smtp://' . $dsnAuth . $smtpHost . ':' . $smtpPort;

            $smtpSecure = $confMail['smtp_secure'] ?? null;
            if (is_string($smtpSecure) && in_array($smtpSecure, ['ssl', 'tls'], true)) {
                $dsn .= '?encryption=' . $smtpSecure;
            }
        } else {
            // Matches PHPMailer's default (non-SMTP) behavior, which sends via PHP's native mail().
            $dsn = 'native://default';
        }

        $mailer = new Mailer(Transport::fromDsn($dsn));

        $ret = true;
        $errorMessage = null;
        $preResult = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('before_send_mail', true, $to, $args, $email);

        if ($preResult === true) {
            try {
                $mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $ret = false;
                $errorMessage = $e->getMessage();
            }

            if (! $ret && (! (bool) ini_get('display_errors') || \Piwigo\Auth\AccessControl::isAdmin())) {
                trigger_error('Mailer Error: ' . $errorMessage, \E_USER_WARNING);
            }
            if (\Piwigo\Config\CurrentConfig::debugMail()) {
                $this->sendMailTest($ret, $email, $args, $errorMessage);
            }
        }

        return $ret;
    }

    /**
     * Moves CSS rules contained in the <style> tag to inline CSS. Used for
     * compatibility with Gmail and such clients.
     */
    public function moveCssToBody(string $content): string
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
     * Saves a copy of the mail in _data/tmp.
     *
     * @param array<string, mixed> $args
     */
    public function sendMailTest(bool $success, Email $mail, array $args, ?string $errorMessage = null): void
    {
        $dataLocation = \Piwigo\Config\CurrentConfig::dataLocation();

        $dir = CurrentPaths::get()->root . $dataLocation . 'tmp';
        if (\Piwigo\Core\FilesystemHelper::mkgetdir($dir, \Piwigo\Core\FilesystemHelper::MKGETDIR_DEFAULT & ~\Piwigo\Core\FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            $username = \Piwigo\Users\CurrentUser::get()->username;
            $langCode = Lang::langInfo()['code'] ?? null;
            $langCode = is_string($langCode) ? $langCode : '';

            $filename = $dir . '/mail.' . stripslashes($username) . '.' . $langCode . '-' . date('YmdHis') . ($success ? '' : '.ERROR');
            $filename .= $args['content_format'] === 'text/plain' ? '.txt' : '.html';

            $file = fopen($filename, 'w+');
            if ($file === false) {
                return;
            }
            if (! $success) {
                fwrite($file, 'ERROR: ' . $errorMessage . "\n\n");
            }
            fwrite($file, $mail->toString());
            fclose($file);
        }
    }

    /**
     * Generates the reset-password mail content.
     *
     * @return array{subject: string, content: string, content_format: string}
     */
    public function generateResetPasswordMail(string $username, string $passwordLink, string $galleryTitle, string $remainingTime): array
    {
        $this->urlService()
            ->setMakeFullUrl();

        $message = '<p style="margin: 20px 0">';
        $message = Lang::t('Someone requested that the password be reset for the following user account:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . Lang::t('To reset your password, visit the following address:');
        $message .= ' <a href="' . $passwordLink . '">' . Lang::t('Change my password') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%;">' . $passwordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= Lang::t('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= Lang::t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        $this->urlService()
            ->unsetMakeFullUrl();

        $messageAfterTrigger = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_lost_password_mail_content', $message);
        $message = is_string($messageAfterTrigger) ? $messageAfterTrigger : $message;

        return [
            'subject' => '[' . $galleryTitle . '] ' . Lang::t('Password Reset'),
            'content' => $message,
            'content_format' => 'text/html',
        ];
    }

    /**
     * Generates the set-password mail content.
     *
     * @return array{subject: string, content: string, content_format: string}
     */
    public function generateSetPasswordMail(string $username, string $setPasswordLink, string $galleryTitle, string $remainingTime): array
    {
        $this->urlService()
            ->setMakeFullUrl();

        $message = '<p style="margin: 20px 0">';
        $message .= Lang::t('A photo library administrator has created the following account for you:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . Lang::t('To set your password, visit the following address:');
        $message .= ' <a href="' . $setPasswordLink . '">' . Lang::t('Activate') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%; margin: 20px 0;">' . $setPasswordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= Lang::t('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= Lang::t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        $this->urlService()
            ->unsetMakeFullUrl();

        $messageAfterTrigger = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_lost_password_mail_content', $message);
        $message = is_string($messageAfterTrigger) ? $messageAfterTrigger : $message;

        return [
            'subject' => Lang::t('Welcome to %s', $galleryTitle),
            'content' => $message,
            'content_format' => 'text/html',
        ];
    }

    /**
     * Generates the user-code-verification mail content.
     *
     * @return array{subject: string, content: string, content_format: string}
     */
    public function generateCodeVerificationMail(string $code): array
    {
        $this->urlService()
            ->setMakeFullUrl();
        $message = '<p style="margin: 20px 0">';
        $message .= Lang::t('Here is your verification code:') . ' <br />';
        $message .= '<span style="font-size: 16px">' . $code . '</span></p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= Lang::t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';
        $this->urlService()
            ->unsetMakeFullUrl();

        $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();

        return [
            'subject' => '[' . $galleryTitle . '] ' . Lang::t('Your verification code'),
            'content' => $message,
            'content_format' => 'text/html',
        ];
    }

    /**
     * Generates the reset-password-success mail content.
     *
     * @return array{subject: string, content: string, content_format: string}
     */
    public function generateSuccessResetPasswordMail(string $username, int $nbOfApikeys): array
    {
        $this->urlService()
            ->setMakeFullUrl();
        $profileUrl = $this->urlService()
            ->getRootUrl() . 'profile.php';

        $message = '<p style="margin-top: 20px;">' . Lang::t('Hello %s,', $username) . '</p>';
        $message .= '<p style="margin-bottom: 20px;">' . Lang::t('Your password was successfully reset') . '.</p>';
        $message .= '<p>';
        $message .= Lang::t('If this wasn\'t you, please change your password immediately or contact your webmaster.');
        $message .= '</p>';

        if ($nbOfApikeys > 0) {
            $message .= '<p style="margin: 20px 0;">';
            $message .= Lang::t(
                'If you changed your password because you think it was stolen, we recommend revoking your %d API keys <a href="%s">in your profile</a>.',
                $nbOfApikeys,
                $profileUrl
            );
            $message .= '</p>';
        }
        $this->urlService()
            ->unsetMakeFullUrl();

        $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();

        return [
            'subject' => '[' . $galleryTitle . '] ' . Lang::t('Your password has been reset'),
            'content' => $message,
            'content_format' => 'text/html',
        ];
    }
}
