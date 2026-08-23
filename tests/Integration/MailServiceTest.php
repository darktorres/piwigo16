<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\Projection\MailArgs;
use Piwigo\Core\Projection\MailOptions;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Event\LoadingLang;
use Piwigo\Lang\Translator;
use Piwigo\Mail\Event\BeforeParseMailTemplate;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\MailRecipientRepositoryInterface;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\MailServiceTestSpyTransport;
use Piwigo\Tests\Support\MailServiceTestTransportSwap;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * Piwigo\Mail\MailService::mailNotificationAdmins()/mailAdmins()/
 * mailGroup()/mail()/sendMailTest() -- the real DB-backed, network-touching
 * methods this class's own MailServiceTest (Unit suite) deliberately
 * doesn't reach (that file only exercises pure helpers -- formatEmail(),
 * unformatEmail(), getCleanRecipientsList(), etc).
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
 * user_group: user 1 is in group 1 only, user 3 is in groups 1 and
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

    #[Override]
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
        $configService = new ConfigService($repo, CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $configService->loadConfFromDb();

        $mailer = Kernel::container()->get(MailService::class);
        self::assertInstanceOf(MailService::class, $mailer);
        $this->mailer = $mailer;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE users SET mail_address = NULL WHERE id = 3');
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '';
        $currentConfig->debugMail = false;
        // CurrentUserTestFactory::get()->attachGlobals() is a lazy-init `??=` -- once
        // setCurrentUserToFixtureAdmin() has called CurrentUserTestFactory::get()->set(),
        // attachGlobals() alone is a no-op (self::$instance is already
        // non-null) and the webmaster identity would leak into every
        // later test in this file/process. reset() first is required.
        CurrentUserTestFactory::get()->reset();
        CurrentUserTestFactory::get()->attachGlobals();
        Kernel::reset();
        parent::tearDown();
    }

    private function buildUserService(): UserService
    {
        $paths = Kernel::container()->get(Paths::class);
        self::assertInstanceOf(Paths::class, $paths);
        $permissionService = Kernel::container()->get(PermissionService::class);
        self::assertInstanceOf(PermissionService::class, $permissionService);
        $categoryService = Kernel::container()->get(CategoryService::class);
        self::assertInstanceOf(CategoryService::class, $categoryService);
        $passwordService = Kernel::container()->get(PasswordService::class);
        self::assertInstanceOf(PasswordService::class, $passwordService);

        return new UserService(
            LangTestFactory::get(),
            new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get()),
            EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
            new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)),
            HtmlServiceTestFactory::build(),
            new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
            new EventDispatcher(),
            new DeploymentPolicy(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            new InstallationFlag(),
            new ProcessCache(),
            $paths,
            EntityManagerFactory::build($this->conn),
            $permissionService,
            $categoryService,
            $passwordService,
        );
    }

    /**
     * Same real-container-resolved collaborators as $this->mailer's own
     * setUp() construction, but with a caller-supplied
     * MailRecipientRepositoryInterface swapped in.
     */
    private function mailServiceWithRecipientRepo(MailRecipientRepositoryInterface $repo): MailService
    {
        $lang = Kernel::container()->get(Lang::class);
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        $paths = Kernel::container()->get(Paths::class);
        $translator = Kernel::container()->get(Translator::class);
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        $currentUser = Kernel::container()->get(CurrentUser::class);
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        $webmasterMailProvider = Kernel::container()->get(WebmasterMailProviderInterface::class);
        $authService = Kernel::container()->get(AuthService::class);
        $userService = Kernel::container()->get(UserService::class);
        $pageState = Kernel::container()->get(PageState::class);
        $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $lang instanceof Lang || ! $currentConfig instanceof CurrentConfig
            || ! $paths instanceof Paths
            || ! $translator instanceof Translator || ! $eventDispatcher instanceof EventDispatcher
            || ! $currentUser instanceof CurrentUser || ! $urlService instanceof UrlServiceInterface
            || ! $webmasterMailProvider instanceof WebmasterMailProviderInterface
            || ! $authService instanceof AuthService
            || ! $userService instanceof UserService
            || ! $pageState instanceof PageState
            || ! $htmlRenderer instanceof HtmlRenderingInterface
            || ! $imageStdParams instanceof ImageStdParams
        ) {
            throw new LogicException('Container returned an unexpected type');
        }

        return new MailService($lang, $currentConfig, $paths, $translator, $eventDispatcher, $currentUser, $urlService, $webmasterMailProvider, $repo, $authService, $userService, $pageState, $htmlRenderer, $imageStdParams);
    }

    private function setCurrentUserToFixtureAdmin(): void
    {
        $data = $this->buildUserService()
            ->buildUser(UserId::from(1));
        CurrentUserTestFactory::get()->set(User::fromUserArray($data));
    }

    /**
     * A plain @ does NOT stop PHPUnit's ErrorHandler from surfacing the
     * expected "Mailer Error" trigger_error() below regardless -- @ only
     * affects error_reporting(), not whether the handler chain runs -- a
     * real no-op error handler for the duration of the one
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

    /**
     * Same real-injectable-transport technique as this class's own
     * Unit-suite sibling (Unit/Mail/MailServiceTest.php's own
     * mail_service_capture_send()) -- a MailServiceTestSpyTransport,
     * swapped in via MailServiceTestTransportSwap on $this->mailer,
     * captures the fully-built Email one step before a real
     * Transport::send() would run, so these assertions read the real
     * Symfony\Component\Mime\Email's own decoded getHtmlBody()/
     * getTextBody() (MIME-transfer-encoding-safe) rather than needing
     * suppressMailerWarning()'s closed-port trick or sendMailTest()'s own
     * raw dumped-file content.
     *
     * @param string|array<int|string, mixed> $to
     * @return array{email: Email}
     */
    private function mailCaptureBeforeSend(string|array $to, ?MailArgs $args = null, ?MailOptions $tpl = null): array
    {
        $spy = new MailServiceTestSpyTransport();
        $spyMailer = MailServiceTestTransportSwap::with($this->mailer, $spy);

        $spyMailer->mail($to, $args, $tpl);

        if ($spy->sent === []) {
            throw new RuntimeException('expected mail() to have sent through the spy transport');
        }

        return [
            'email' => $spy->sent[0],
        ];
    }

    public function testMailNotificationAdminsReturnsFalseForAnEmptySubject(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('', 'content'));
    }

    public function testMailNotificationAdminsReturnsFalseForEmptyContent(): void
    {
        self::assertFalse($this->mailer->mailNotificationAdmins('subject', ''));
    }

    public function testMailNotificationAdminsSendsToTheOnlyAdminAndFailsDeliveryDeterministically(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailNotificationAdmins('Test subject', 'Test content'));

        self::assertFalse($result);
    }

    public function testMailNotificationAdminsBuildsSubjectAndContentFromLangArgsArrays(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';
        $subject = LangTestFactory::get()->buildArgs('Registration of %s', 'someuser');
        $content = [
            LangTestFactory::get()->buildArgs('User: %s', 'someuser'),
            LangTestFactory::get()->buildArgs('Email: %s', 'someuser@example.test'),
        ];

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailNotificationAdmins($subject, $content));

        self::assertFalse($result);
    }

    public function testMailAdminsReturnsFalseWhenContentAndTplAreBothEmpty(): void
    {
        self::assertFalse($this->mailer->mailAdmins(new MailArgs(), new MailOptions()));
    }

    public function testMailAdminsReturnsTrueWhenExcludingTheCurrentUserLeavesNoAdmin(): void
    {
        // user 1 (fixture_admin) is the fixture's only webmaster/admin --
        // excluding it as the current user (the default) empties the
        // recipient list entirely, so mailAdmins() short-circuits to
        // `true` (see its own "$admins === []" branch) without ever
        // reaching mail().
        $this->setCurrentUserToFixtureAdmin();

        self::assertTrue($this->mailer->mailAdmins(new MailArgs(content: 'hi'), new MailOptions()));
    }

    public function testMailAdminsSendsToTheAdminAndFailsDeliveryDeterministically(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailAdmins(new MailArgs(content: 'hi'), new MailOptions()));

        self::assertFalse($result);
    }

    public function testMailAdminsOnlyWebmastersStillFindsTheWebmaster(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailAdmins(new MailArgs(content: 'hi'), new MailOptions(), true, true));

        self::assertFalse($result);
    }

    public function testMailGroupReturnsFalseForGroupZero(): void
    {
        self::assertFalse($this->mailer->mailGroup(0, new MailArgs(content: 'hi'), new MailOptions()));
    }

    public function testMailGroupReturnsFalseWhenContentAndTplAreBothEmpty(): void
    {
        self::assertFalse($this->mailer->mailGroup(1, new MailArgs(), new MailOptions()));
    }

    public function testMailGroupReturnsTrueForAGroupWithNoLanguageMatches(): void
    {
        self::assertTrue($this->mailer->mailGroup(99999, new MailArgs(content: 'hi'), new MailOptions()));
    }

    public function testMailGroupSendsToTheSingleRealRecipientAndFailsDeliveryDeterministically(): void
    {
        // Group 1 has user 1 (real email) and user 3 (NULL email, filtered
        // out at the query level) -- exactly one real send attempt.
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailGroup(1, new MailArgs(content: 'hi')));

        self::assertFalse($result);
    }

    public function testMailGroupBuildsAnAuthKeyLinkForANormalStatusRecipientWithARealEmail(): void
    {
        // Temporary, restored in tearDown: user 3 (status 'normal', so
        // AuthService::createUserAuthKey() actually succeeds, unlike user
        // 1's 'webmaster' status) is already in group 2 with no other
        // real-email member, giving a single deterministic recipient that
        // exercises the authkey!==false LINK-building branch.
        $this->conn->executeStatement(
            "UPDATE users SET mail_address = 'temp3@example.test' WHERE id = 3"
        );
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailGroup(2, new MailArgs(content: 'hi'), new MailOptions(
            assign: [
                'LINK' => 'http://example.test/link',
            ],
        )));

        self::assertFalse($result);
    }

    public function testMailReturnsTrueImmediatelyWhenToAndCcAndBccAreAllEmpty(): void
    {
        self::assertTrue($this->mailer->mail('', new MailArgs()));
    }

    public function testMailAttemptsDeliveryWhenOnlyCcIsSetAndToIsEmpty(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail('', new MailArgs(
            cc: 'cc@example.test',
            content: 'hi',
        )));

        self::assertFalse($result);
    }

    public function testSendMailTestDumpsALabelledErrorFileWhenDebugMailIsEnabled(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '127.0.0.1:1';
        $currentConfig->debugMail = true;
        $dir = dirname(__DIR__, 2) . '/_data/tmp';
        $before = glob($dir . '/mail.*');
        self::assertIsArray($before);

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(
                subject: 'hi',
                content: 'body',
                contentFormat: 'text/plain',
            )
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

    public function testSendMailTestReturnsEarlyWhenTheTargetFileCannotBeOpened(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '127.0.0.1:1';
        $currentConfig->debugMail = true;
        // A username containing '/' makes sendMailTest()'s own filename
        // resolve into a non-existent subdirectory of _data/tmp --
        // fopen('w+') doesn't create missing intermediate directories, so
        // it fails, exercising the "file couldn't be opened" early return.
        // suppressMailerWarning() below also swallows the resulting
        // fopen() warning, not just the expected "Mailer Error" one.
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: Username::from('nonexistent-subdir/evil'),
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $dir = dirname(__DIR__, 2) . '/_data/tmp';
        $before = glob($dir . '/mail.*');
        self::assertIsArray($before);

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(
                subject: 'hi',
                content: 'body',
                contentFormat: 'text/plain',
            )
        ));
        self::assertFalse($result);

        $after = glob($dir . '/mail.*');
        self::assertIsArray($after);
        self::assertSame($before, $after);
    }

    public function testSwitchLangToLoadsLanguageFilesAndNotifiesLoadingLangForALanguageNotYetCached(): void
    {
        $notified = false;
        $handler = static function () use (&$notified): void {
            $notified = true;
        };
        EventDispatcherTestFactory::get()->addTypedHandler(LoadingLang::class, $handler);

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
            EventDispatcherTestFactory::get()->removeTypedHandler(LoadingLang::class, $handler);
            $this->mailer->switchLangBack();
        }
    }

    public function testSwitchLangToReplaysEveryRegisteredPluginLanguageFileForALanguageNotYetCached(): void
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
        LangTestFactory::get()->load('missing.lang', 'my-plugin/', [
            'language' => 'en_UK',
        ]);

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
                [
                    'language' => 'en_UK',
                ],
                LangTestFactory::get()->languageFiles()['my-plugin/']['missing.lang']
            );
        } finally {
            $this->mailer->switchLangBack();
            // LangTestFactory::get()'s own $languageFiles is container-shared/
            // process-shared and this test is the only one in the suite
            // that ever populates it -- without this, the 'my-plugin/'
            // entry above would leak into every later test in this process.
            LangTestFactory::get()->reset();
        }
    }

    public function testSwitchLangBackIsANoOpWhenTheStackIsEmpty(): void
    {
        // A fresh $this->mailer (this file's own setUp(), a brand new
        // instance) starts with an empty $switchLangStack -- calling
        // switchLangBack()
        // without a prior switchLangTo() must return immediately rather
        // than array_pop()-ing an empty stack.
        //
        // Not expectNotToPerformAssertions(): this file's own setUp()
        // already performs a real assertInstanceOf() guard, which PHPUnit
        // counts against every test in the class -- that combination is
        // what PHPUnit's risky-test detector flags ("not expected to
        // perform assertions but performed N").
        $this->mailer->switchLangBack();
    }

    public function testMailGroupFiltersRecipientsByAnExplicitLanguageSelectedArgument(): void
    {
        // Same fixture shape as the "single real recipient" test above
        // (group 1: user 1 real+English, user 3 NULL email) -- explicitly
        // requesting 'en_UK' here exercises language_selected's own
        // non-empty ternary branch, not just its default-null fallback.
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailGroup(1, new MailArgs(content: 'hi'), null, 'en_UK'));

        self::assertFalse($result);
    }

    public function testMailGroupSkipsAnEmptyStringLanguageEntry(): void
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
        $realRepo = new MailRecipientRepository(EntityManagerFactory::build($this->conn));
        $repo = new readonly class($realRepo) implements MailRecipientRepositoryInterface {
            public function __construct(
                private MailRecipientRepositoryInterface $real
            ) {}

            #[Override]
            public function findAdminsAndWebmasters(array $userStatuses, ?int $groupId, ?int $excludeUserId): array
            {
                return $this->real->findAdminsAndWebmasters($userStatuses, $groupId, $excludeUserId);
            }

            #[Override]
            public function findDistinctLanguagesInGroup(int $groupId, ?string $languageFilter): array
            {
                return [''];
            }

            #[Override]
            public function findByGroupAndLanguage(int $groupId, string $language): array
            {
                return $this->real->findByGroupAndLanguage($groupId, $language);
            }
        };
        $mailer = $this->mailServiceWithRecipientRepo($repo);

        // The single '' entry is `continue`d over without ever reaching
        // findByGroupAndLanguage()/switchLangTo() -- $return stays at its
        // initial `true`, same as the real "no matching users" outcome.
        self::assertTrue($mailer->mailGroup(1, new MailArgs(content: 'hi')));
    }

    public function testMailGroupSkipsALanguageWhosePerLanguageLookupComesBackWithNoUsers(): void
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
        $realRepo = new MailRecipientRepository(EntityManagerFactory::build($this->conn));
        $repo = new readonly class($realRepo) implements MailRecipientRepositoryInterface {
            public function __construct(
                private MailRecipientRepositoryInterface $real
            ) {}

            #[Override]
            public function findAdminsAndWebmasters(array $userStatuses, ?int $groupId, ?int $excludeUserId): array
            {
                return $this->real->findAdminsAndWebmasters($userStatuses, $groupId, $excludeUserId);
            }

            #[Override]
            public function findDistinctLanguagesInGroup(int $groupId, ?string $languageFilter): array
            {
                return ['en_UK'];
            }

            #[Override]
            public function findByGroupAndLanguage(int $groupId, string $language): array
            {
                return [];
            }
        };
        $mailer = $this->mailServiceWithRecipientRepo($repo);

        self::assertTrue($mailer->mailGroup(1, new MailArgs(content: 'hi')));
    }

    public function testMailGroupBuildsAnAuthKeyLinkForTheOptionalIMGAssignSlotToo(): void
    {
        // Same user-3-temp-email fixture trick as the LINK-only test
        // above, this time also supplying an IMG assign slot with its own
        // 'link' key to exercise that separate auth-key-appending branch.
        $this->conn->executeStatement(
            "UPDATE users SET mail_address = 'temp3@example.test' WHERE id = 3"
        );
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mailGroup(
            2,
            new MailArgs(content: 'hi'),
            new MailOptions(assign: [
                'LINK' => 'http://example.test/link',
                'IMG' => [
                    'link' => 'http://example.test/img.png',
                ],
            ])
        ));

        self::assertFalse($result);
    }

    public function testMailAutoAddsABccToTheWebmasterWhenSendBccMailWebmasterIsEnabled(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '127.0.0.1:1';
        $currentConfig->sendBccMailWebmaster = true;

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(content: 'hi')
        ));

        self::assertFalse($result);
    }

    public function testMailFallsBackToRawContentWhenTheFilenameIsUnrecognised(): void
    {
        // buildRuntimeTemplateView() only recognises 'notification_admin'/
        // 'cat_group_info' (the 2 real in-tree $tpl['filename'] values,
        // confirmed by an exhaustive grep) -- any other value returns
        // null, and mail() falls back to appending $mailContent plain,
        // same as when no 'filename' is set at all.
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(content: 'hi'),
            new MailOptions(filename: 'this_template_does_not_exist')
        ));

        self::assertFalse($result);
    }

    public function testMailBuildsAnSmtpDsnWithTheDefaultPortUrlEncodedAuthAndEncryptionWhenConfigured(): void
    {
        // No colon in smtp_host -> defaults to port 25; a configured
        // smtp_user/password -> rawurlencode()d DSN auth; smtp_secure
        // 'tls' -> appends '?encryption=tls'. 127.0.0.1 has no local mail
        // daemon listening on port 25 in this environment, so the
        // connection is refused just as fast/deterministically as the
        // ':1'-suffixed closed port every other test in this file uses.
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '127.0.0.1';
        $currentConfig->smtpUser = 'mail user';
        $currentConfig->smtpPassword = 'p@ss w/ord';
        $currentConfig->smtpSecure = 'tls';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(content: 'hi')
        ));

        self::assertFalse($result);
    }

    public function testFormatEmailStripsNewlinesFromTheEmailItselfNotJustTheName(): void
    {
        // Real gap found via mutation testing: the sibling "strips
        // newlines from both name and email" Unit test (Unit/Mail/
        // MailServiceTest.php) only puts the newline in $name -- $email
        // there is already clean, so it never actually exercises
        // $cvtEmail's own preg_replace() call.
        self::assertSame(
            '"Name" <abc@example.test>',
            $this->mailer->formatEmail('Name', "abc@example.test\r\n")
        );
    }

    public function testGetCleanRecipientsListReturnsAnEmptyListForALiteralBoolFalse(): void
    {
        // Real gap found via mutation testing: the sibling "returns an
        // empty list for a literal int 0..." Unit test names false in
        // its own title ("matching its own null/""/[]/false peers") but
        // never actually passes it.
        self::assertSame([], $this->mailer->getCleanRecipientsList(false));
    }

    public function testSwitchLangToLoadsAdminLangTranslationsForALanguageNotYetCached(): void
    {
        // "Access type" is translated in language/fr_FR/admin.po ("Type
        // d'accès") but never appears anywhere in language/fr_FR/
        // common.po -- only actually calling
        // $this->lang->load('admin.lang', ...) for the real 'fr_FR'
        // language (not just common.lang, and not some other language
        // reached via a corrupted dirname/options array) makes it
        // translate; an untranslated lookup returns the raw key back
        // unchanged.
        try {
            $this->mailer->switchLangTo('fr_FR');

            self::assertSame("Type d'accès", TranslatorTestFactory::get()->translate('Access type'));
        } finally {
            $this->mailer->switchLangBack();
        }
    }

    public function testSwitchLangToRestoresTheCachedLanguagesOwnDataAndTranslatorDictionaryWhenReusingIt(): void
    {
        // First pass genuinely populates the switchLangLanguages cache
        // entry for 'fr_FR' (the real re-init branch, exercised by the
        // sibling test above).
        $this->mailer->switchLangTo('fr_FR');
        $this->mailer->switchLangBack();

        // Corrupt BOTH the Lang::snapshot()-backed data array and the
        // real Translator dictionary with a marker that was never part
        // of the 'fr_FR' cache entry captured above -- Lang::loadArray()
        // writes both at once (its own docblock).
        LangTestFactory::get()->loadArray([
            'switch_lang_to_cached_restore_marker' => 'CORRUPTED',
        ]);

        try {
            // Second switchLangTo('fr_FR') call hits the ELSE/cached
            // branch this time (the entry populated above is already
            // there) -- must overwrite $data/translator FROM that real
            // cached snapshot, not leave the marker injected right above
            // in place.
            $this->mailer->switchLangTo('fr_FR');

            self::assertArrayNotHasKey('switch_lang_to_cached_restore_marker', LangTestFactory::get()->snapshot());
            self::assertSame(
                'switch_lang_to_cached_restore_marker',
                TranslatorTestFactory::get()->translate('switch_lang_to_cached_restore_marker')
            );
        } finally {
            $this->mailer->switchLangBack();
        }
    }

    public function testSwitchLangBackRestoresTheSavedLanguagesOwnDataAndTranslatorDictionaryNotJustLangInfo(): void
    {
        // Populates $switchLangLanguages[originalLanguage] with the
        // CURRENT (pre-switch) Lang::snapshot()/Translator dictionary
        // state -- "considered OK on first call" (switchLangTo()'s own
        // comment).
        LangTestFactory::get()->loadArray([
            'switch_lang_back_restore_marker' => 'ORIGINAL',
        ]);

        $this->mailer->switchLangTo('fr_FR');

        // Corrupt the CURRENT (now fr_FR) data/dictionary --
        // switchLangBack() must overwrite these FROM the snapshot
        // captured above, not leave this corruption in place.
        LangTestFactory::get()->loadArray([
            'switch_lang_back_restore_marker' => 'CORRUPTED',
        ]);

        $this->mailer->switchLangBack();

        self::assertSame([
            'switch_lang_back_restore_marker' => 'ORIGINAL',
        ], LangTestFactory::get()->snapshot());
        self::assertSame('ORIGINAL', TranslatorTestFactory::get()->translate('switch_lang_back_restore_marker'));
    }

    public function testMailFallsBackTheCacheKeysLangCodeSegmentToAnEmptyStringWhenLangInfoHasNoCode(): void
    {
        // BeforeParseMailTemplate's own real, observable cacheKey payload
        // is the only way to inspect $cacheKey from outside -- it's
        // otherwise a fully internal, opaque string (see this class's
        // own "cache key... only its UNIQUENESS" reasoning, Unit/Mail/
        // MailServiceTest.php). Forcing langInfo() to have no 'code' key
        // at all (rather than trusting whatever this shared process
        // happens to have left it at) exercises $langCode's own
        // is_string()-guarded fallback. The handler is never removed
        // between the html/plain iterations, so $capturedCacheKey ends
        // up holding the LAST dispatch -- 'text/plain' is always
        // processed last (unconditionally appended after the optional
        // 'text/html' entry), so this assertion holds regardless of
        // mail_allow_html's own current value.
        LangTestFactory::get()->setLangInfo([]);
        $capturedCacheKey = null;
        $handler = function (BeforeParseMailTemplate $event) use (&$capturedCacheKey): void {
            $capturedCacheKey = $event->cacheKey;
        };
        EventDispatcherTestFactory::get()->addTypedHandler(BeforeParseMailTemplate::class, $handler);

        try {
            $this->mailCaptureBeforeSend(
                [
                    'name' => 'Someone',
                    'email' => 'someone@example.test',
                ],
                new MailArgs(subject: 'x', content: 'y', theme: 'clear')
            );
        } finally {
            EventDispatcherTestFactory::get()->removeTypedHandler(BeforeParseMailTemplate::class, $handler);
        }

        self::assertSame('text/plain--clear', $capturedCacheKey);
    }

    public function testMailLeavesPlainTextContentUntouchedWhenBothContentFormatAndContentTypeArePlain(): void
    {
        // content_format defaults to 'text/plain' (unset here) and
        // mail_allow_html is forced false (contentType list is ONLY
        // ['text/plain']) -- content_format and contentType end up the
        // SAME format, the one combination neither conversion branch's
        // own `&&` condition (content_format==='text/plain' &&
        // contentType==='text/html', or the mirrored elseif) is written
        // to match, so this is the only way to reach the final
        // passthrough `else`. Kills BOTH branches' own
        // BooleanAndToBooleanOr mutation: either turned into `||` would
        // make ITS OWN condition true here too (content_format===
        // 'text/plain' is true; contentType==='text/plain' is true),
        // wrongly converting this content (htmlspecialchars+wrap, or
        // strip_tags) instead of leaving it untouched.
        CurrentConfigTestFactory::get()->mailAllowHtml = false;

        $result = $this->mailCaptureBeforeSend(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(subject: 'x', content: 'Keep <this> tag as-is')
        );

        $textBody = $result['email']->getTextBody();
        self::assertIsString($textBody);
        self::assertStringContainsString('Keep <this> tag as-is', $textBody);
    }

    public function testMailWrapsTheConvertedHtmlPartInAnOpeningAndClosingParagraphTag(): void
    {
        // content_format defaults to 'text/plain' and mail_allow_html is
        // forced true (contentType list includes 'text/html') -- for
        // that html iteration, content_format==='text/plain' &&
        // contentType==='text/html' is the ONLY real branch that
        // matches, converting the raw content into
        // '<p>' . nl2br(...) . '</p>'. Uses a regex (not an exact `<p>`
        // substring match) because moveCssToBody()'s own Emogrifier pass
        // adds a real "style=..." attribute onto that same <p> tag (see
        // the sibling Unit test's own docblock) -- the tag itself is
        // never removed or reordered by that pass, only decorated.
        CurrentConfigTestFactory::get()->mailAllowHtml = true;
        LangTestFactory::get()->setLangInfo([
            'code' => 'en',
            'direction' => 'ltr',
        ]);

        $result = $this->mailCaptureBeforeSend(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(subject: 'x', content: 'MarkerContentXYZ')
        );

        $htmlBody = $result['email']->getHtmlBody();
        self::assertIsString($htmlBody);
        self::assertMatchesRegularExpression('/<p[^>]*>\s*MarkerContentXYZ\s*<\/p>/', $htmlBody);
    }

    public function testMailTurnsOffFullUrlModeBeforeReturning(): void
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        self::assertInstanceOf(UrlServiceInterface::class, $urlService);
        $before = $urlService->getRootUrl();
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(content: 'hi')
        ));

        self::assertSame($before, $urlService->getRootUrl());
    }

    public function testMailBuildsAStillParseableSmtpDsnWhenBothSmtpUserAndHostAreConfigured(): void
    {
        // Reordering dsnAuth ('user:pass@') BEFORE the 'smtp://' scheme
        // instead of after it (as a concatenation bug would) makes
        // parse_url() find no
        // 'scheme'/'host' key at all, so Symfony\Component\Mailer\
        // Transport\Dsn::fromString() throws InvalidArgumentException
        // ('The mailer DSN must contain a scheme.') the moment
        // buildMailer() parses it -- BEFORE the real connection attempt
        // below, and OUTSIDE the try/catch that normally turns a failed
        // send into a plain `false` return, so it would propagate
        // uncaught out of this test instead. A real, non-empty
        // smtp_user/password is required to make dsnAuth non-empty in
        // the first place -- an empty dsnAuth reorders to nothing
        // observably different.
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->smtpHost = '127.0.0.1:1';
        $currentConfig->smtpUser = 'mail user';
        $currentConfig->smtpPassword = 'p@ss w/ord';

        $result = $this->suppressMailerWarning(fn (): bool => $this->mailer->mail(
            [
                'name' => 'Someone',
                'email' => 'someone@example.test',
            ],
            new MailArgs(content: 'hi')
        ));

        self::assertFalse($result);
    }

    public function testGenerateSuccessResetPasswordMailTurnsOffFullUrlModeBeforeReturning(): void
    {
        // Unlike generateResetPasswordMail/generateSetPasswordMail/
        // generateCodeVerificationMail (see Unit/Mail/MailServiceTest.php's
        // own docblock: their setMakeFullUrl()/unsetMakeFullUrl() pairs
        // are confirmed-equivalent since neither method calls urlService()
        // for anything else), this method's own trailing
        // unsetMakeFullUrl() call genuinely matters: $profileUrl is
        // computed while full-url mode is active, but the flag itself is
        // real, LIFO-stacked, request-scoped state (UrlService::
        // setMakeFullUrl()/unsetMakeFullUrl() push/pop a stack) that
        // leaks into every LATER urlService call in the same request if
        // never popped back off.
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        self::assertInstanceOf(UrlServiceInterface::class, $urlService);
        $before = $urlService->getRootUrl();

        $this->mailer->generateSuccessResetPasswordMail('jane', 0);

        self::assertSame($before, $urlService->getRootUrl());
    }
}
