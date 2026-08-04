<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\MailRecipientRepositoryInterface;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Mail\MailService::mailNotificationAdmins()/mailAdmins()/
 * mailGroup()/mail()/sendMailTest() -- the real DB-backed, network-touching
 * methods this class's own MailServiceTest (Unit suite) deliberately
 * doesn't reach (that file only exercises pure helpers -- formatEmail(),
 * unformatEmail(), getCleanRecipientsList(), etc). See
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1.
 *
 * Same deterministic-delivery-failure trick as
 * NotificationByMailSenderTest (see that class's own docblock for the full
 * reasoning): `smtp_host` pointed at a real closed local port makes every
 * real send fail near-instantly and deterministically, which
 * MailService::mail()'s own trigger_error(E_USER_WARNING) turns into a
 * PHP warning failOnWarning="true" would otherwise fail the test on -- a
 * plain `@` does NOT stop PHPUnit's ErrorHandler from surfacing it
 * regardless, so every call that can reach a real send goes through this
 * class's own suppressMailerWarning() (a real no-op error handler for the
 * duration of the call).
 *
 * Fixture shape this file depends on: only user 1 (fixture_admin) has a
 * real mail_address (users 2-4 are NULL) and is the fixture's only
 * webmaster/admin (status 'webmaster', users 3/4 are 'normal') -- see
 * piwigo_user_group: user 1 is in group 1 only, user 3 is in groups 1 and
 * 2. This makes user 1 double as both "the only admin" and "the only
 * group-1 member with a real email", and user 3 (status 'normal', so
 * AuthService::createUserAuthKey() actually succeeds for it, unlike
 * user 1's 'webmaster' status) the natural pick for a temporary real
 * mail_address to exercise mailGroup()'s auth-key-link branch without a
 * lasting fixture mutation.
 *
 * moveCssToBody()'s catch(\Exception) fallback (see this class's own
 * MailServiceTest.php sibling in the Unit suite for the happy path) is
 * deliberately not chased here: CssInliner::fromHtml()->inlineCss() only
 * throws ParseException/\RuntimeException for an invalid selector/XPath
 * when debug mode is on (see vendor/pelago/emogrifier/src/CssInliner.php's
 * own docblocks), and moveCssToBody() never calls setDebug(true) -- no
 * public seam exists to force that branch from a black-box caller without
 * relying on fragile, version-specific internal Emogrifier behavior.
 *
 * mailGroup()'s own `if ($language === '')` and `if ($users === [])`
 * guards are both structurally unreachable through the *real*
 * MailRecipientRepository pair: findDistinctLanguagesInGroup() already
 * filters out empty-string languages via array_filter(), and
 * findByGroupAndLanguage() runs the identical group/email/language join
 * conditions -- any language the first query returns is guaranteed at
 * least one row in the second. Both are still exercised (see
 * test_mailGroup_skips_an_empty_string_language_entry() and
 * test_mailGroup_skips_a_language_whose_per_language_lookup_comes_back_with_no_users()
 * below) via MailService's own second constructor parameter -- an
 * explicit substitution seam its own docblock documents as existing for
 * exactly this ("unit tests ... pass a fake implementation") -- rather
 * than left permanently uncovered.
 */
final class MailServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private MailService $mailer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();

        $this->conn = DbConnection::build();
        $repo = EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class);
        self::assertInstanceOf(\Piwigo\Config\ConfigRepository::class, $repo);
        $configService = new ConfigService($repo, new \Piwigo\PluginConfig\EventDispatcher());
        CurrentConfigService::current()->set($configService);
        $configService->loadConfFromDb();

        $this->mailer = new MailService();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::users() . ' SET mail_address = NULL WHERE id = 3');
        CurrentConfig::setSmtpHost('');
        CurrentConfig::setDebugMail(false);
        // CurrentUser::current()->attachGlobals() is a lazy-init `??=` -- once
        // setCurrentUserToFixtureAdmin() has called CurrentUser::current()->set(),
        // attachGlobals() alone is a no-op (self::$instance is already
        // non-null) and the webmaster identity would leak into every
        // later test in this file/process. reset() first is required.
        CurrentUser::current()->reset();
        CurrentUser::current()->attachGlobals();
        Kernel::reset();
        parent::tearDown();
    }

    private function buildUserService(): UserService
    {
        return new UserService(
            EntityManagerFactory::build($this->conn)->getRepository(UserInfoEntity::class),
            EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
            new MailService(),
            new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)),
            new HtmlService(),
            $this->conn,
            new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class)),
            new \Piwigo\PluginConfig\EventDispatcher(),
            new \Piwigo\Config\DeploymentPolicy(),
            \Piwigo\Users\CurrentUser::current(),
        );
    }

    private function setCurrentUserToFixtureAdmin(): void
    {
        $data = $this->buildUserService()->buildUser(UserId::from(1));
        CurrentUser::current()->set(User::fromUserArray($data));
    }

    /**
     * A plain @ does NOT stop PHPUnit's ErrorHandler from surfacing the
     * expected "Mailer Error" trigger_error() below regardless (confirmed:
     * @ only affects error_reporting(), not whether the handler chain
     * runs) -- a real no-op error handler for the duration of the one
     * expected-to-warn call is the only reliable way to swallow it,
     * matching ImageGdTest's own established pattern.
     */
    private function suppressMailerWarning(callable $fn): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function test_mailNotificationAdmins_returns_false_for_an_empty_subject(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('', 'content'));
    }

    public function test_mailNotificationAdmins_returns_false_for_empty_content(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('subject', ''));
    }

    public function test_mailNotificationAdmins_sends_to_the_only_admin_and_fails_delivery_deterministically(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailNotificationAdmins('Test subject', 'Test content'));

        self::assertFalse($result);
    }

    public function test_mailNotificationAdmins_builds_subject_and_content_from_lang_args_arrays(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        $subject = Lang::buildArgs('Registration of %s', 'someuser');
        $content = [
            Lang::buildArgs('User: %s', 'someuser'),
            Lang::buildArgs('Email: %s', 'someuser@example.test'),
        ];

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailNotificationAdmins($subject, $content));

        self::assertFalse($result);
    }

    public function test_mailAdmins_returns_false_when_content_and_tpl_are_both_empty(): void
    {
        self::assertFalse($this->mailer->mailAdmins([], []));
    }

    public function test_mailAdmins_returns_true_when_excluding_the_current_user_leaves_no_admin(): void
    {
        // user 1 (fixture_admin) is the fixture's only webmaster/admin --
        // excluding it as the current user (the default) empties the
        // recipient list entirely, so mailAdmins() short-circuits to
        // `true` (see its own "$admins === []" branch) without ever
        // reaching mail().
        $this->setCurrentUserToFixtureAdmin();

        self::assertTrue($this->mailer->mailAdmins(['content' => 'hi'], []));
    }

    public function test_mailAdmins_sends_to_the_admin_and_fails_delivery_deterministically(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailAdmins(['content' => 'hi'], []));

        self::assertFalse($result);
    }

    public function test_mailAdmins_only_webmasters_still_finds_the_webmaster(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailAdmins(['content' => 'hi'], [], true, true));

        self::assertFalse($result);
    }

    public function test_mailGroup_returns_false_for_group_zero(): void
    {
        self::assertFalse($this->mailer->mailGroup(0, ['content' => 'hi'], []));
    }

    public function test_mailGroup_returns_false_when_content_and_tpl_are_both_empty(): void
    {
        self::assertFalse($this->mailer->mailGroup(1, [], []));
    }

    public function test_mailGroup_returns_true_for_a_group_with_no_language_matches(): void
    {
        self::assertTrue($this->mailer->mailGroup(99999, ['content' => 'hi'], []));
    }

    public function test_mailGroup_sends_to_the_single_real_recipient_and_fails_delivery_deterministically(): void
    {
        // Group 1 has user 1 (real email) and user 3 (NULL email, filtered
        // out at the query level) -- exactly one real send attempt.
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(1, ['content' => 'hi']));

        self::assertFalse($result);
    }

    public function test_mailGroup_builds_an_auth_key_link_for_a_normal_status_recipient_with_a_real_email(): void
    {
        // Temporary, restored in tearDown: user 3 (status 'normal', so
        // AuthService::createUserAuthKey() actually succeeds, unlike user
        // 1's 'webmaster' status) is already in group 2 with no other
        // real-email member, giving a single deterministic recipient that
        // exercises the authkey!==false LINK-building branch.
        $this->conn->executeStatement(
            "UPDATE " . Tables::users() . " SET mail_address = 'temp3@example.test' WHERE id = 3"
        );
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(2, ['content' => 'hi'], ['assign' => ['LINK' => 'http://example.test/link']]));

        self::assertFalse($result);
    }

    public function test_mail_returns_true_immediately_when_to_and_cc_and_bcc_are_all_empty(): void
    {
        self::assertTrue($this->mailer->mail('', []));
    }

    public function test_mail_attempts_delivery_when_only_cc_is_set_and_to_is_empty(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail('', ['Cc' => 'cc@example.test', 'content' => 'hi']));

        self::assertFalse($result);
    }

    public function test_sendMailTest_dumps_a_labelled_error_file_when_debug_mail_is_enabled(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setDebugMail(true);
        $dir = dirname(__DIR__, 2) . '/_data/tmp';
        $before = glob($dir . '/mail.*');
        self::assertIsArray($before);

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['subject' => 'hi', 'content' => 'body', 'content_format' => 'text/plain']
        ));
        self::assertFalse($result);

        $after = glob($dir . '/mail.*');
        self::assertIsArray($after);
        $created = array_values(array_diff($after, $before));

        self::assertCount(1, $created);
        self::assertStringEndsWith('.ERROR.txt', $created[0]);
        $contents = file_get_contents($created[0]);
        self::assertIsString($contents);
        self::assertStringStartsWith('ERROR: ', $contents);

        unlink($created[0]);
    }

    public function test_sendMailTest_returns_early_when_the_target_file_cannot_be_opened(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setDebugMail(true);
        // A username containing '/' makes sendMailTest()'s own filename
        // resolve into a non-existent subdirectory of _data/tmp --
        // fopen('w+') doesn't create missing intermediate directories, so
        // it fails, exercising the "file couldn't be opened" early return.
        // suppressMailerWarning() below also swallows the resulting
        // fopen() warning, not just the expected "Mailer Error" one.
        CurrentUser::current()->set(new User(
            id: UserId::from(1),
            username: 'nonexistent-subdir/evil',
            email: '',
            language: 'en_UK',
            theme: '',
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $dir = dirname(__DIR__, 2) . '/_data/tmp';
        $before = glob($dir . '/mail.*');
        self::assertIsArray($before);

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['subject' => 'hi', 'content' => 'body', 'content_format' => 'text/plain']
        ));
        self::assertFalse($result);

        $after = glob($dir . '/mail.*');
        self::assertIsArray($after);
        self::assertSame($before, $after);
    }

    public function test_switchLangTo_loads_language_files_and_notifies_loading_lang_for_a_language_not_yet_cached(): void
    {
        $notified = false;
        $handler = static function () use (&$notified): void {
            $notified = true;
        };
        EventDispatcher::get()->addTypedHandler(LoadingLang::class, $handler);

        try {
            // A fresh $this->mailer (this file's own setUp(), a brand new
            // instance) starts with an empty $switchLangLanguages cache --
            // 'fr_FR'
            // (a real installed language, see language/fr_FR/common.po)
            // has never been loaded yet, so this exercises the real
            // re-init branch (Lang::load() for common.lang/admin.lang/
            // every registered plugin lang file, then the loading_lang
            // notify) -- not the already-cached restore branch every
            // other switchLangTo() call in this file happens to hit
            // (CurrentUser's own guest language and
            // userService()->getDefaultLanguage() are both 'en_UK', so
            // the very first switchLangTo() call in those tests
            // self-populates the cache for its own target language).
            $this->mailer->switchLangTo('fr_FR');

            self::assertTrue($notified);
        } finally {
            EventDispatcher::get()->removeEventHandler(LoadingLang::class, $handler);
            $this->mailer->switchLangBack();
        }
    }

    public function test_switchLangTo_replays_every_registered_plugin_language_file_for_a_language_not_yet_cached(): void
    {
        // Simulates what a real plugin's own 'loading_lang' handler would
        // have registered earlier in the request: Lang::load() with a
        // non-empty dirname tracks into Lang::languageFiles() (see
        // Core\Lang::load()'s own tracking guard, and its sibling test in
        // LangTest.php). switchLangTo()'s re-init branch for a language
        // not yet cached (same branch the sibling test above exercises)
        // must replay every one of those registered files for the new
        // target language -- this is the only path that reaches that
        // foreach's own body (MailService.php lines ~453-456), not just
        // the empty-map zero-iteration case the sibling test alone would
        // leave covered.
        Lang::load('missing.lang', 'my-plugin/', ['language' => 'en_UK']);

        try {
            // Must not throw/warn even though 'my-plugin/missing.lang.php'
            // doesn't exist on disk -- Lang::load() resolves that to a
            // quiet `return false;` (file_exists() found nothing), same as
            // a real plugin whose language file is missing for the target
            // language.
            $this->mailer->switchLangTo('de_DE');

            // The re-registration inside the replay loop hits Lang::load()'s
            // own "already tracked" guard (isset() short-circuits before
            // overwrite), so the original entry -- not the 'de_DE' the loop
            // passed through -- is what's still on record afterward.
            self::assertSame(
                ['language' => 'en_UK'],
                Lang::languageFiles()['my-plugin/']['missing.lang']
            );
        } finally {
            $this->mailer->switchLangBack();
            // Lang::$languageFiles is static/process-shared and this test
            // is the only one in the suite that ever populates it --
            // without this, the 'my-plugin/' entry above would leak into
            // every later test in this process.
            Lang::reset();
        }
    }

    public function test_switchLangBack_is_a_no_op_when_the_stack_is_empty(): void
    {
        // A fresh $this->mailer (this file's own setUp(), a brand new
        // instance) starts with an empty $switchLangStack -- calling
        // switchLangBack()
        // without a prior switchLangTo() must return immediately rather
        // than array_pop()-ing an empty stack.
        //
        // Not expectNotToPerformAssertions(): this file's own setUp()
        // already performs a real assertInstanceOf() guard, which PHPUnit
        // counts against every test in the class -- confirmed live, that
        // combination is what PHPUnit's risky-test detector flags ("not
        // expected to perform assertions but performed N").
        $this->mailer->switchLangBack();
    }

    public function test_mailGroup_filters_recipients_by_an_explicit_language_selected_argument(): void
    {
        // Same fixture shape as the "single real recipient" test above
        // (group 1: user 1 real+English, user 3 NULL email) -- explicitly
        // requesting 'en_UK' here exercises language_selected's own
        // non-empty ternary branch, not just its default-null fallback.
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(1, ['content' => 'hi', 'language_selected' => 'en_UK']));

        self::assertFalse($result);
    }

    public function test_mailGroup_skips_an_empty_string_language_entry(): void
    {
        // MailRecipientRepository::findDistinctLanguagesInGroup()'s own
        // real implementation already array_filter()s out empty-string
        // languages before returning (is_string($language) &&
        // $language !== '') -- no genuine DB read can ever hand
        // MailService::mailGroup() an empty-string language element to
        // skip via the real repository. mailGroup()'s own second
        // constructor parameter exists precisely for this kind of
        // substitution (see MailService::__construct()'s own docblock:
        // "unit tests ... pass a fake implementation"), so overriding just
        // this one read method -- while still exercising every other real
        // MailService/MailRecipientRepository code path -- is the only way
        // to reach this guard's own continue branch. MailRecipientRepository
        // is final, so the substitute wraps a real instance (composition)
        // and delegates every method it doesn't need to override to it,
        // rather than extending the final class directly.
        $realRepo = new MailRecipientRepository($this->conn);
        $repo = new class ($realRepo) implements MailRecipientRepositoryInterface {
            public function __construct(private readonly MailRecipientRepositoryInterface $real) {}

            #[\Override]
            public function findAdminsAndWebmasters(string $idColumn, string $usernameColumn, string $emailColumn, array $userStatuses, ?int $groupId, ?int $excludeUserId): array
            {
                return $this->real->findAdminsAndWebmasters($idColumn, $usernameColumn, $emailColumn, $userStatuses, $groupId, $excludeUserId);
            }

            #[\Override]
            public function findDistinctLanguagesInGroup(string $idColumn, string $emailColumn, int $groupId, ?string $languageFilter): array
            {
                return [''];
            }

            #[\Override]
            public function findByGroupAndLanguage(string $idColumn, string $usernameColumn, string $emailColumn, int $groupId, string $language): array
            {
                return $this->real->findByGroupAndLanguage($idColumn, $usernameColumn, $emailColumn, $groupId, $language);
            }
        };
        $mailer = new MailService(mailRecipientRepo: $repo);

        // The single '' entry is `continue`d over without ever reaching
        // findByGroupAndLanguage()/switchLangTo() -- $return stays at its
        // initial `true`, same as the real "no matching users" outcome.
        self::assertTrue($mailer->mailGroup(1, ['content' => 'hi']));
    }

    public function test_mailGroup_skips_a_language_whose_per_language_lookup_comes_back_with_no_users(): void
    {
        // findDistinctLanguagesInGroup() and findByGroupAndLanguage() run
        // the exact same WHERE predicates (group_id + non-empty email [+
        // language]) against the same 3 joined tables -- for a single
        // sequential DB read with no concurrent writer, any language the
        // first query returns is therefore guaranteed to have >=1 matching
        // row in the second. This guard defends mailGroup() against a real
        // production race (another admin changing group membership/
        // language between those two queries) that a single-connection
        // test can't reproduce by timing alone -- overriding
        // findByGroupAndLanguage() to come back empty is the only
        // deterministic way to exercise it, same reasoning/seam as the
        // empty-string-language test above.
        $realRepo = new MailRecipientRepository($this->conn);
        $repo = new class ($realRepo) implements MailRecipientRepositoryInterface {
            public function __construct(private readonly MailRecipientRepositoryInterface $real) {}

            #[\Override]
            public function findAdminsAndWebmasters(string $idColumn, string $usernameColumn, string $emailColumn, array $userStatuses, ?int $groupId, ?int $excludeUserId): array
            {
                return $this->real->findAdminsAndWebmasters($idColumn, $usernameColumn, $emailColumn, $userStatuses, $groupId, $excludeUserId);
            }

            #[\Override]
            public function findDistinctLanguagesInGroup(string $idColumn, string $emailColumn, int $groupId, ?string $languageFilter): array
            {
                return ['en_UK'];
            }

            #[\Override]
            public function findByGroupAndLanguage(string $idColumn, string $usernameColumn, string $emailColumn, int $groupId, string $language): array
            {
                return [];
            }
        };
        $mailer = new MailService(mailRecipientRepo: $repo);

        self::assertTrue($mailer->mailGroup(1, ['content' => 'hi']));
    }

    public function test_mailGroup_builds_an_auth_key_link_for_the_optional_IMG_assign_slot_too(): void
    {
        // Same user-3-temp-email fixture trick as the LINK-only test
        // above, this time also supplying an IMG assign slot with its own
        // 'link' key to exercise that separate auth-key-appending branch.
        $this->conn->executeStatement(
            "UPDATE " . Tables::users() . " SET mail_address = 'temp3@example.test' WHERE id = 3"
        );
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mailGroup(
            2,
            ['content' => 'hi'],
            ['assign' => ['LINK' => 'http://example.test/link', 'IMG' => ['link' => 'http://example.test/img.png']]]
        ));

        self::assertFalse($result);
    }

    public function test_mail_auto_adds_a_bcc_to_the_webmaster_when_send_bcc_mail_webmaster_is_enabled(): void
    {
        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setSendBccMailWebmaster(true);

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['content' => 'hi']
        ));

        self::assertFalse($result);
    }

    public function test_mail_sets_a_custom_template_dir_then_falls_back_to_raw_content_when_the_filename_does_not_exist(): void
    {
        // 'dirname' set (any value -- Smarty's addTemplateDir() appends
        // rather than validates/replaces, so this alone can't hide the
        // real theme's own templates) exercises set_template_dir() being
        // called at all; the genuinely nonexistent 'filename' is what
        // actually forces templateExists() to return false, independent
        // of dirname.
        CurrentConfig::setSmtpHost('127.0.0.1:1');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['content' => 'hi'],
            ['filename' => 'this_template_does_not_exist', 'dirname' => '_data/mail_templates_extra', 'assign' => []]
        ));

        self::assertFalse($result);
    }

    public function test_mail_builds_an_smtp_dsn_with_the_default_port_url_encoded_auth_and_encryption_when_configured(): void
    {
        // No colon in smtp_host -> defaults to port 25; a configured
        // smtp_user/password -> rawurlencode()d DSN auth; smtp_secure
        // 'tls' -> appends '?encryption=tls'. 127.0.0.1 has no local mail
        // daemon listening on port 25 in this environment, so the
        // connection is refused just as fast/deterministically as the
        // ':1'-suffixed closed port every other test in this file uses.
        CurrentConfig::setSmtpHost('127.0.0.1');
        CurrentConfig::setSmtpUser('mail user');
        CurrentConfig::setSmtpPassword('p@ss w/ord');
        CurrentConfig::setSmtpSecure('tls');

        $result = $this->suppressMailerWarning(fn () => $this->mailer->mail(
            ['name' => 'Someone', 'email' => 'someone@example.test'],
            ['content' => 'hi']
        ));

        self::assertFalse($result);
    }
}
