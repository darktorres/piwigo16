<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Exception;
use InvalidArgumentException;
use LogicException;
use Override;
use Pelago\Emogrifier\CssInliner;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Event\Mail\BeforeParseMailTemplate;
use Piwigo\Event\Mail\BeforeSendMail;
use Piwigo\Event\Mail\RenderLostPasswordMailContent;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Mail\Projection\EmailRecipient;
use Piwigo\Mail\Projection\MailContent;
use Piwigo\Mail\Projection\MailHeaderPageContext;
use Piwigo\Mail\Projection\MailRecipient;
use Piwigo\Mail\Projection\MailRuntimeTemplatePageContext;
use Piwigo\Mail\Projection\MailTitlePageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
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
 * Reads mail-related settings through `Piwigo\Config\CurrentConfig`'s typed
 * accessors. `Piwigo\Config\ConfigDb::loadConfFromDb()` (called from
 * common.inc.php on every real request) syncs every DB-persisted config row
 * into both the legacy `$conf` global and `CurrentConfig::$data`, so mail
 * settings read here (debug_mail, smtp_host, mail_sender_name, ...) reflect
 * real admin-configured values rather than install-time defaults.
 *
 * Accepts an optional WebmasterMailProviderInterface test seam (see the
 * constructor's own docblock). Remaining cross-domain calls (l10n(),
 * trigger_notify()/trigger_change()) stay as plain global-function calls
 * to the composer-autoloaded procedural helpers; l10n() itself stays a
 * bare call (see src/Piwigo/Lang/functions.php).
 *
 * The template-render cache and language-switch stack are request-scoped
 * state with no other reader in the codebase -- kept as private instance
 * state, with an instance reset() for test isolation.
 *
 * `AccessLevelChecker` has no `MailerInterface` dependency of its own, so
 * it's built directly from this class's own already-required
 * currentUser/currentConfig rather than resolved from the container.
 * `AdminContext`/`ErrorCollector`/`ProcessCache`/`CurrentConfigService` are
 * resolved lazily from the container instead, purely to pass through to
 * getMailTemplate()'s own `new Template(...)` call -- see adminContext()'s
 * own docblock for why they stay lazy.
 *
 * Implements `Piwigo\Core\MailerInterface` so L2aCoreDomain/L2bExtendedDomain
 * classes that may not depend on this class directly (this file is
 * L3Presentation) can constructor-inject the interface instead —
 * `Users\UserService`/`Comment\CommentService`, bound via
 * `config/container.php`.
 */
final class MailService implements MailerInterface
{
    /**
     * A real visitor's synchronous HTTP request (e.g. RegisterController's
     * "email me my connection settings" registration option) must never
     * hang indefinitely on a slow/unreachable mail transport -- see
     * buildMailer()'s own docblock.
     */
    private const MAIL_TRANSPORT_TIMEOUT_SECONDS = 10.0;

    /**
     * Optional-with-lazy-default rather than required: the dependency is
     * only reached on the sender-fallback/Bcc-webmaster paths -- callers
     * that never reach them get the real Piwigo\Users\UserRepository (a
     * legal L3->L2a downward dep, constructed lazily so no DB connection
     * is built for the many code paths that never need it); unit tests
     * pass a fake implementation instead.
     */
    public function __construct(
        private readonly Lang $lang,
        private readonly CurrentConfig $currentConfig,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly PageState $pageState,
        private readonly Paths $paths,
        private readonly SessionService $sessionService,
        private readonly Translator $translator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentUser $currentUser,
        private readonly UrlServiceInterface $urlService,
        private readonly ?WebmasterMailProviderInterface $webmasterMailProvider = null,
        private readonly ?MailRecipientRepositoryInterface $mailRecipientRepo = null,
        private readonly ?AuthService $authService = null,
    ) {}

    /**
     * Built from this class's own already-required currentUser/
     * currentConfig -- AccessLevelChecker has no MailerInterface dependency
     * of its own, so this needs no container resolve at all.
     */
    private function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker($this->currentUser, $this->currentConfig);
    }

    /**
     * Container resolve, not a constructor property -- these 4 exist
     * purely to pass through to getMailTemplate()'s own
     * `new Template(...)` call (Template's own required collaborators),
     * not read by MailService itself. Resolving `Piwigo\Auth\AccessControl`
     * anywhere already transitively autowires this class -- its
     * constructor requires RedirectServiceInterface ->
     * Bootstrap\RedirectService -> Users\UserService -> Core\MailerInterface
     * (this class, autowired) -- so a required constructor param here for
     * any collaborator with an eager-side-effect container factory would
     * mean every such resolution also pays that cost. Kept lazy so nothing
     * forces it outside an actual getMailTemplate() call.
     */
    private function adminContext(): AdminContext
    {
        $adminContext = Kernel::container()->get(AdminContext::class);
        if (! $adminContext instanceof AdminContext) {
            throw new LogicException('Container returned an unexpected type for ' . AdminContext::class);
        }

        return $adminContext;
    }

    private function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }

        return $errorCollector;
    }

    /**
     * Container resolve, not a constructor property -- same reasoning as
     * adminContext()/errorCollector()/processCache()/currentConfigService()
     * above. Used only inside authService()/userService()'s own
     * throwaway-fallback constructions below, for HtmlService's own
     * required constructor collaborators.
     */
    private function htmlRenderer(): HtmlRenderingInterface
    {
        $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
        if (! $htmlRenderer instanceof HtmlRenderingInterface) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlRenderingInterface::class);
        }

        return $htmlRenderer;
    }

    private function processCache(): ProcessCache
    {
        $processCache = Kernel::container()->get(ProcessCache::class);
        if (! $processCache instanceof ProcessCache) {
            throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
        }

        return $processCache;
    }

    /**
     * Same reasoning as processCache() above -- used only inside
     * userService()'s own throwaway-fallback construction below, for
     * UserService's own required InstallationFlag/ProcessCache
     * collaborators.
     */
    private function installationFlag(): InstallationFlag
    {
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        if (! $installationFlag instanceof InstallationFlag) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
        }

        return $installationFlag;
    }

    private function currentConfigService(): CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }

        return $currentConfigService;
    }

    private function webmasterMailAddress(): string
    {
        $provider = $this->webmasterMailProvider
            ?? new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig);

        return $provider->getWebmasterMailAddress();
    }

    /**
     * Optional-with-lazy-default, same reasoning as
     * $webmasterMailProvider above -- most callers never reach
     * mailAdmins()/mailGroup() at all.
     */
    private function recipientRepo(): MailRecipientRepositoryInterface
    {
        return $this->mailRecipientRepo
            ?? new MailRecipientRepository(EntityManagerFactory::build(DbConnection::build()));
    }

    /**
     * Optional-with-lazy-default, same reasoning as
     * $webmasterMailProvider above -- only mailGroup() reaches this. Unlike UserService (which
     * genuinely can't be a constructor dependency here -- UserService
     * constructor-depends on MailerInterface, i.e. this class, a real
     * cycle), AuthService doesn't depend back on MailerInterface, so this
     * is just the usual high-caller-count lazy-default, not a circular-
     * dependency workaround.
     */
    private function authService(): AuthService
    {
        return $this->authService
            ?? new AuthService(
                new AuthRepository(EntityManagerFactory::build(DbConnection::build())),
                new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)),
                $this->htmlRenderer(),
                new PasswordService(new PasswordRepository(EntityManagerFactory::build(DbConnection::build())), $this->deploymentPolicy),
                new CookieService(),
                EntityManagerFactory::build(DbConnection::build())->getRepository(UserFailedLoginEntity::class),
                $this->sessionService,
                $this->eventDispatcher,
                $this->pageState,
                $this->currentUser,
                $this->currentConfig,
                $this->paths,
            );
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
    private function userService(): UserService
    {
        return new UserService(
            $this->lang,
            new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig),
            EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class),
            new self($this->lang, $this->currentConfig, $this->deploymentPolicy, $this->pageState, $this->paths, $this->sessionService, $this->translator, $this->eventDispatcher, $this->currentUser, $this->urlService),
            new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)),
            $this->htmlRenderer(),
            DbConnection::build(),
            $this->sessionService,
            $this->eventDispatcher,
            $this->deploymentPolicy,
            $this->currentUser,
            $this->currentConfig,
            $this->installationFlag(),
            $this->processCache(),
            $this->paths,
        );
    }

    /**
     * @var array<string, array{theme: Template}>
     */
    private array $templateCache = [];

    private bool $switchLangInitialised = false;

    /**
     * @var list<string>
     */
    private array $switchLangStack = [];

    /**
     * @var array<string, array{lang_info: array<string, string|bool>, lang: array<string, string|array<int, string>>, translator: Translator}>
     */
    private array $switchLangLanguages = [];

    public function reset(): void
    {
        $this->templateCache = [];
        $this->switchLangInitialised = false;
        $this->switchLangStack = [];
        $this->switchLangLanguages = [];
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

    public function getMailSenderName(): string
    {
        $senderName = $this->currentConfig->mailSenderName();
        if ($senderName !== '') {
            return $senderName;
        }

        $galleryTitle = $this->currentConfig->galleryTitle();

        return $galleryTitle;
    }

    public function getMailSenderEmail(): string
    {
        $senderEmail = $this->currentConfig->mailSenderEmail();
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
        $smtpHost = $this->currentConfig->smtpHost();

        return [
            'send_bcc_mail_webmaster' => $this->currentConfig->sendBccMailWebmaster(),
            'mail_allow_html' => $this->currentConfig->mailAllowHtml(),
            'mail_theme' => $this->currentConfig->mailTheme(),
            'use_smtp' => $smtpHost !== '',
            'smtp_host' => $smtpHost,
            'smtp_user' => $this->currentConfig->smtpUser(),
            'smtp_password' => $this->currentConfig->smtpPassword(),
            'smtp_secure' => is_string($this->currentConfig->smtpSecure()) ? $this->currentConfig->smtpSecure() : null,
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
     */
    public function unformatEmail(string|array $input): EmailRecipient
    {
        if (is_array($input)) {
            if (! isset($input['email']) || ! is_string($input['email'])) {
                throw new InvalidArgumentException(__METHOD__ . '(): array input must contain a string "email" key');
            }

            return new EmailRecipient(
                $input['email'],
                isset($input['name']) && is_string($input['name']) ? $input['name'] : ''
            );
        }

        if (preg_match('/(.*)<(.*)>.*/', $input, $matches) === 1) {
            return new EmailRecipient(trim($matches[2]), trim($matches[1]));
        }

        return new EmailRecipient(trim($input), '');
    }

    /**
     * Returns a clean array of hashmaps (email, name), removing duplicates.
     * Accepts a comma-separated list, an array of emails, a single hashmap
     * (email[, name]), or an array of incomplete hashmaps -- $data stays
     * mixed by design: it's a multi-shape dispatcher (see the accepted
     * shapes above), not a single reusable contract, and every shape is
     * validated internally before use.
     *
     * @return list<EmailRecipient>
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
                        $entries[] = new EmailRecipient(trim(is_scalar($item) ? (string) $item : ''), '');
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
                        $entries[] = new EmailRecipient(is_scalar($item) ? trim((string) $item) : '', '');
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
            if (isset($existing[$entry->email])) {
                continue;
            }
            $existing[$entry->email] = true;
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
        return new Template($this->currentConfig, $this->lang, $this->adminContext(), $this->eventDispatcher, $this->pageState, $this->errorCollector(), $this->processCache(), $this->currentConfigService(), $this->paths, $this->accessLevelChecker(), $this->sessionService, $this->paths->root . 'themes', 'default', 'template/mail/' . $emailFormat);
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
        $currentUserLanguage = $this->currentUser->get()
            ->language->value;

        // Language of the current user is saved (considered OK on first call).
        if (! $this->switchLangInitialised && ! isset($this->switchLangLanguages[$currentUserLanguage])) {
            $this->switchLangInitialised = true;
            $this->switchLangLanguages[$currentUserLanguage] = [
                'lang_info' => $this->lang->langInfo(),
                'lang' => $this->lang->snapshot(),
                // \Piwigo\Core\Lang's own $data/$langInfo are just parallel
                // bookkeeping (has()/langInfo()) -- the real translations
                // t() actually reads live in the separate Translator
                // singleton's gettext dictionary, which $this->lang->snapshot()/
                // restore() never touch. A clone (Translator::__clone()
                // deep-copies its own $inner) is the only way to capture
                // and later restore that real state too.
                'translator' => clone $this->translator,
            ];
        }

        $this->switchLangStack[] = $currentUserLanguage;
        $this->currentUser->updateLanguage(LangCode::tryFrom($language) ?? LangCode::from(AppInfo::DEFAULT_LANGUAGE));

        if (! isset($this->switchLangLanguages[$language])) {
            // Re-init language arrays.
            $this->lang->setLangInfo([]);
            $this->lang->restore(null);

            $this->lang->load('common.lang', '', [
                'language' => $language,
            ]);
            // No test admin because script is checked admin (user selected no).
            // Translations are in admin file too.
            $this->lang->load('admin.lang', '', [
                'language' => $language,
            ]);

            // Reload all plugin files (see $this->lang->load()'s own docblock).
            foreach ($this->lang->languageFiles() as $dirname => $files) {
                foreach ($files as $filename => $options) {
                    $options['language'] = $language;
                    $this->lang->load($filename, $dirname, $options);
                }
            }

            $this->eventDispatcher->dispatchNotify(new LoadingLang());
            $this->lang->load(
                'lang',
                $this->paths->siteLocal,
                [
                    'language' => $language,
                    'no_fallback' => true,
                    'local' => true,
                ]
            );

            $this->switchLangLanguages[$language] = [
                'lang_info' => $this->lang->langInfo(),
                'lang' => $this->lang->snapshot(),
                'translator' => clone $this->translator,
            ];
        } else {
            $entry = $this->switchLangLanguages[$language];
            $this->lang->setLangInfo($entry['lang_info']);
            $this->lang->restore($entry['lang']);
            $this->translator->restoreFrom($entry['translator']);
        }
    }

    /**
     * Switches back to the language pushed with switchLangTo(). Language
     * files are not reloaded.
     */
    public function switchLangBack(): void
    {
        if ($this->switchLangStack === []) {
            return;
        }

        $language = array_pop($this->switchLangStack);

        if (isset($this->switchLangLanguages[$language])) {
            $entry = $this->switchLangLanguages[$language];
            $this->lang->setLangInfo($entry['lang_info']);
            $this->lang->restore($entry['lang']);
            $this->translator->restoreFrom($entry['translator']);
        }
        $this->currentUser->updateLanguage(LangCode::tryFrom($language) ?? LangCode::from(AppInfo::DEFAULT_LANGUAGE));
    }

    /**
     * Sends a notification email to all administrators. The current user
     * (if admin) is not notified.
     *
     * @param string|array{key_args: array<int, mixed>} $subject
     * @param string|list<array{key_args: array<int, mixed>}> $content
     * @param bool $sendTechnicalDetails send user IP and browser
     */
    #[Override]
    public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool
    {
        if ($subject === '' || $content === '') {
            return false;
        }

        if (is_array($subject) || is_array($content)) {
            $this->switchLangTo($this->userService()->getDefaultLanguage());

            if (is_array($subject)) {
                $subject = $this->lang->args($subject);
            }
            if (is_array($content)) {
                $content = $this->lang->args($content);
            }

            $this->switchLangBack();
        }

        $tplVars = [];
        if ($sendTechnicalDetails) {
            $username = $this->currentUser->get()
                ->username->value ?? '';
            $tplVars['TECHNICAL'] = [
                'username' => stripslashes($username),
                'ip' => IpAddress::fromRemoteAddr()->value ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ];
        }

        $galleryTitle = $this->currentConfig->galleryTitle();

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
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args as in mail()
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

        $admins = $this->recipientRepo()
            ->findAdminsAndWebmasters(
                $userStatuses,
                $groupId !== null ? (int) $groupId : null,
                $excludeCurrentUser ? $this->currentUser->get()
                    ->id->value : null,
            );

        if ($admins === []) {
            return true;
        }

        // mail()'s own $to parameter is a deliberately dynamic, many-shapes
        // contract (used by every other caller too, see
        // getCleanRecipientsList()'s own docblock) -- converted back to
        // array form here, at this one boundary, rather than widening that
        // shared contract to also understand a real MailRecipient object.
        $adminRows = array_map(static fn (MailRecipient $r): array => $r->toArray(), $admins);

        $this->switchLangTo($this->userService()->getDefaultLanguage());
        $return = $this->mail($adminRows, $args, $tpl);
        $this->switchLangBack();

        return $return;
    }

    /**
     * Sends an email to a group.
     *
     * @param array{language_selected?: string, from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args as in mail() -- language_selected filters users of the group by language
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl as in mail()
     */
    public function mailGroup(int $groupId, array $args = [], array $tpl = []): bool
    {
        if ($groupId === 0 || ((! isset($args['content']) || self::emptyValue($args['content'])) && $tpl === [])) {
            return false;
        }

        $return = true;

        $languageSelected = isset($args['language_selected']) && ! self::emptyValue($args['language_selected'])
            ? $args['language_selected']
            : null;

        $languages = $this->recipientRepo()
            ->findDistinctLanguagesInGroup(
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
                    $userTpl['assign']['LINK'] = $this->urlService->addUrlParams(is_string($link) ? $link : '', [
                        'auth' => $authkey['auth_key'],
                    ]);

                    $img = $userTpl['assign']['IMG'] ?? null;
                    if (is_array($img) && isset($img['link']) && is_string($img['link'])) {
                        $img['link'] = $this->urlService->addUrlParams(
                            $img['link'],
                            [
                                'auth' => $authkey['auth_key'],
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
                    $userArgs['auth_key'] = $authkey['auth_key'];
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
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
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
    #[Override]
    public function mail(string|array $to, array $args = [], array $tpl = []): bool
    {
        if (self::emptyValue($to) && (! isset($args['Cc']) || self::emptyValue($args['Cc'])) && (! isset($args['Bcc']) || self::emptyValue($args['Bcc']))) {
            return true;
        }

        $confMail = $this->getMailConfiguration();

        $email = new Email();

        foreach ($this->getCleanRecipientsList($to) as $recipient) {
            $email->addTo(new Address($recipient->email, $recipient->name));
        }

        // Compute root_path in order to have a complete path.
        $this->urlService
            ->setMakeFullUrl();

        if (! isset($args['from']) || self::emptyValue($args['from'])) {
            $from = new EmailRecipient(
                is_string($confMail['email_webmaster']) ? $confMail['email_webmaster'] : '',
                is_string($confMail['name_webmaster']) ? $confMail['name_webmaster'] : ''
            );
        } else {
            $from = $this->unformatEmail($args['from']);
        }
        $email->from(new Address($from->email, $from->name));
        $replyToMail = $args['reply_to_mail_address'] ?? $from->email;
        $replyToName = $args['reply_to_name'] ?? $from->name;
        $email->replyTo(new Address($replyToMail, $replyToName));

        // Subject.
        if (! isset($args['subject']) || self::emptyValue($args['subject'])) {
            $args['subject'] = 'Piwigo';
        }
        $args['subject'] = trim((string) preg_replace('#[\n\r]+#s', '', $args['subject']));
        $email->subject($args['subject']);

        // Cc.
        if (isset($args['Cc']) && ! self::emptyValue($args['Cc'])) {
            foreach ($this->getCleanRecipientsList($args['Cc']) as $recipient) {
                $email->addCc(new Address($recipient->email, $recipient->name));
            }
        }

        // Bcc.
        $bcc = $this->getCleanRecipientsList($args['Bcc'] ?? null);
        if ($confMail['send_bcc_mail_webmaster'] === true) {
            $bcc[] = new EmailRecipient($this->webmasterMailAddress(), '');
        }
        foreach ($bcc as $recipient) {
            $email->addBcc(new Address($recipient->email, $recipient->name));
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
            $args['mail_title'] = $this->currentConfig->galleryTitle();
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

        $langCode = $this->lang->langInfo()['code'] ?? null;
        $langCode = is_string($langCode) ? $langCode : '';

        $contents = [];
        foreach ($contentTypeList as $contentType) {
            // Key composed of indexes which allow caching mail data. Must
            // include theme -- confirmed-real bug found via mutation-gap
            // test-writing (2026-08-01): the cache entry built below (css
            // file selection at "mail-css-{theme}.tpl") depends on
            // $args['theme'], but the key itself didn't, so two mail()
            // calls in the same request sharing contentType/langCode/
            // auth_key but using DIFFERENT themes would silently reuse the
            // first call's CSS. Present identically in the legacy
            // procedural functions_mail.inc.php and 16.x-rewrite's own
            // MailService -- a genuine, long-standing bug carried forward
            // across all 3 codebases, not a regression introduced here.
            $cacheKey = $contentType . '-' . $langCode . '-' . $args['theme'];
            if (isset($args['auth_key']) && ! self::emptyValue($args['auth_key'])) {
                $cacheKey .= '-' . $args['auth_key'];
            }

            if (! isset($this->templateCache[$cacheKey])) {
                $template = $this->getMailTemplate($contentType);
                $this->templateCache[$cacheKey] = [
                    'theme' => $template,
                ];
                $this->eventDispatcher->dispatchNotify(new BeforeParseMailTemplate($cacheKey, $contentType));

                $template->set_filename('mail_header', 'header.tpl');
                $template->set_filename('mail_footer', 'footer.tpl');

                $addUrlParams = [];
                if (isset($args['auth_key']) && ! self::emptyValue($args['auth_key'])) {
                    $addUrlParams['auth'] = $args['auth_key'];
                }

                $galleryHomeUrl = $this->urlService
                    ->getGalleryHomeUrl();

                $template->assignContext(new MailHeaderPageContext(
                    galleryUrl: $this->urlService
                        ->addUrlParams($galleryHomeUrl, $addUrlParams),
                    galleryTitle: $this->currentConfig->galleryTitle(),
                    version: $this->currentConfig->showVersion() ? AppInfo::VERSION : '',
                    phpwgUrl: AppInfo::URL,
                    contentEncoding: CharsetHelper::getPwgCharset(),
                    contactMail: is_string($confMail['email_webmaster']) ? $confMail['email_webmaster'] : '',
                ));

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

            $template = $this->templateCache[$cacheKey]['theme'];
            $template->assignContext(new MailTitlePageContext(
                mailTitle: $args['mail_title'],
                mailSubtitle: $args['mail_subtitle'],
            ));

            // Header.
            $contents[$contentType] = $template->parse('mail_header', true);

            // Content -- stored in a temp variable; if a content template is
            // used it's assigned to CONTENT, otherwise appended to the mail.
            $contentInput = $args['content'];

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
                    $template->assignContext(new MailRuntimeTemplatePageContext(
                        extra: (isset($tpl['assign']) && ! self::emptyValue($tpl['assign'])) ? $tpl['assign'] : [],
                        content: $mailContent,
                    ));
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
        $this->urlService
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

        $mailer = $this->buildMailer($dsn);

        $ret = true;
        $errorMessage = null;
        $beforeSendMailEvent = $this->eventDispatcher->dispatchChange(new BeforeSendMail(true, $to, $args, $email));

        if ($beforeSendMailEvent->shouldSend) {
            try {
                $mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $ret = false;
                $errorMessage = $e->getMessage();
            }

            if (! $ret && (! (bool) ini_get('display_errors') || $this->accessLevelChecker()->isAdmin())) {
                trigger_error('Mailer Error: ' . $errorMessage, \E_USER_WARNING);
            }
            if ($this->currentConfig->debugMail()) {
                $this->sendMailTest($ret, $email, $args, $errorMessage);
            }
        }

        return $ret;
    }

    /**
     * Transport::fromDsn('native://default') (used whenever no SMTP host
     * is configured, PHPMailer's own former default too) resolves to
     * Symfony's own SendmailTransport whenever `sendmail_path` is set --
     * which shells out via a raw proc_open() with NO timeout mechanism at
     * all, and can block the entire synchronous HTTP request indefinitely
     * if the local MTA hangs trying to actually deliver (rather than
     * failing fast) -- see BoundedSendmailTransport's own docblock for the
     * confirmed reproduction. Swapped for that bounded replacement here.
     *
     * The `smtp://` DSN path (a real, explicitly admin-configured SMTP
     * host) is unaffected -- Symfony's own SocketStream already has an
     * implicit connect/read timeout (`default_socket_timeout`) and, unlike
     * SendmailTransport, is `@internal` upstream with no supported way to
     * tighten that bound from outside the Mailer component.
     */
    private function buildMailer(string $dsn): Mailer
    {
        if ($dsn === 'native://default') {
            $sendmailPath = (string) ini_get('sendmail_path');
            if ($sendmailPath !== '') {
                return new Mailer(new BoundedSendmailTransport($sendmailPath, self::MAIL_TRANSPORT_TIMEOUT_SECONDS));
            }
        }

        return new Mailer(Transport::fromDsn($dsn));
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
            return CssInliner::fromHtml($content)->inlineCss()->render();
        } catch (Exception) {
            return $content;
        }
    }

    /**
     * Saves a copy of the mail in _data/tmp. $args is mail()'s own $args,
     * passed through unchanged after mail()'s own normalization pass has
     * already defaulted content_format -- so, unlike that method's own
     * docblock, content_format is non-optional here.
     *
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     */
    public function sendMailTest(bool $success, Email $mail, array $args, ?string $errorMessage = null): void
    {
        $dataLocation = $this->currentConfig->dataLocation();

        $dir = $this->paths->root . $dataLocation . 'tmp';
        if (FilesystemHelper::mkgetdir($dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            $username = $this->currentUser->get()
                ->username->value ?? '';
            $langCode = $this->lang->langInfo()['code'] ?? null;
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
     */
    public function generateResetPasswordMail(string $username, string $passwordLink, string $galleryTitle, string $remainingTime): MailContent
    {
        $this->urlService
            ->setMakeFullUrl();

        $message = '<p style="margin: 20px 0">';
        $message .= $this->lang->t('Someone requested that the password be reset for the following user account:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('To reset your password, visit the following address:');
        $message .= ' <a href="' . $passwordLink . '">' . $this->lang->t('Change my password') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%;">' . $passwordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= $this->lang->t('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= $this->lang->t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        $this->urlService
            ->unsetMakeFullUrl();

        $message = $this->eventDispatcher->dispatchChange(new RenderLostPasswordMailContent($message))
            ->message;

        return new MailContent(
            '[' . $galleryTitle . '] ' . $this->lang->t('Password Reset'),
            $message,
            'text/html',
        );
    }

    /**
     * Generates the set-password mail content.
     */
    public function generateSetPasswordMail(string $username, string $setPasswordLink, string $galleryTitle, string $remainingTime): MailContent
    {
        $this->urlService
            ->setMakeFullUrl();

        $message = '<p style="margin: 20px 0">';
        $message .= $this->lang->t('A photo library administrator has created the following account for you:') . ' ' . $username . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('To set your password, visit the following address:');
        $message .= ' <a href="' . $setPasswordLink . '">' . $this->lang->t('Activate') . '</a></p>';
        $message .= '<p style="text-align: center; font-size: 70%; margin: 20px 0;">' . $setPasswordLink . '</p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= $this->lang->t('This link is valid for %s. After this time, you will need to request a new link.', $remainingTime);
        $message .= ' ';
        $message .= $this->lang->t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';

        $this->urlService
            ->unsetMakeFullUrl();

        $message = $this->eventDispatcher->dispatchChange(new RenderLostPasswordMailContent($message))
            ->message;

        return new MailContent(
            $this->lang->t('Welcome to %s', $galleryTitle),
            $message,
            'text/html',
        );
    }

    /**
     * Generates the user-code-verification mail content.
     */
    public function generateCodeVerificationMail(string $code): MailContent
    {
        $this->urlService
            ->setMakeFullUrl();
        $message = '<p style="margin: 20px 0">';
        $message .= $this->lang->t('Here is your verification code:') . ' <br />';
        $message .= '<span style="font-size: 16px">' . $code . '</span></p>';
        $message .= '<p style="margin: 20px 0;">';
        $message .= $this->lang->t('If this was a mistake, just ignore this email and nothing will happen.') . '</p>';
        $this->urlService
            ->unsetMakeFullUrl();

        $galleryTitle = $this->currentConfig->galleryTitle();

        return new MailContent(
            '[' . $galleryTitle . '] ' . $this->lang->t('Your verification code'),
            $message,
            'text/html',
        );
    }

    /**
     * Generates the reset-password-success mail content.
     */
    public function generateSuccessResetPasswordMail(string $username, int $nbOfApikeys): MailContent
    {
        $this->urlService
            ->setMakeFullUrl();
        $profileUrl = $this->urlService
            ->getRootUrl() . 'profile.php';

        $message = '<p style="margin-top: 20px;">' . $this->lang->t('Hello %s,', $username) . '</p>';
        $message .= '<p style="margin-bottom: 20px;">' . $this->lang->t('Your password was successfully reset') . '.</p>';
        $message .= '<p>';
        $message .= $this->lang->t('If this wasn\'t you, please change your password immediately or contact your webmaster.');
        $message .= '</p>';

        if ($nbOfApikeys > 0) {
            $message .= '<p style="margin: 20px 0;">';
            $message .= $this->lang->t(
                'If you changed your password because you think it was stolen, we recommend revoking your %d API keys <a href="%s">in your profile</a>.',
                $nbOfApikeys,
                $profileUrl
            );
            $message .= '</p>';
        }
        $this->urlService
            ->unsetMakeFullUrl();

        $galleryTitle = $this->currentConfig->galleryTitle();

        return new MailContent(
            '[' . $galleryTitle . '] ' . $this->lang->t('Your password has been reset'),
            $message,
            'text/html',
        );
    }
}
