<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use ReflectionMethod;

/**
 * Piwigo\Mail\NotificationByMailSender::incMailSentSuccess()/
 * incMailSentFailed()/displayCounterInfo()/nbmMailContentFields()/
 * sendMailNotifications()/doSubscribeUnsubscribeNotificationByMail() --
 * all reachable directly (no need to drive a full subscribe/send admin-page
 * POST). Resolved through the real DI container (PresentationAccessor),
 * same "needs a real DB connection to construct" shape as
 * ExtendedDomainAccessorTest's accessors.
 *
 * A real mail-send's success/failure is normally non-deterministic here
 * (see NotificationByMailSubControllerTest's own docblock: depends on
 * whether the local MTA accepts-then-bounces or outright rejects) -- but
 * pointing `smtp_host` at a real closed local port (127.0.0.1:1, nothing
 * ever listens there) makes Symfony Mailer's EsmtpTransport fail the
 * *connection* itself, deterministically and near-instantly (ECONNREFUSED
 * in ~60ms, not a hang), which MailService::mail() surfaces as a
 * deterministic `false` return. This lets the large "delivery failed"
 * branches of sendMailNotifications()/doSubscribeUnsubscribeNotificationByMail()
 * finally be exercised for real, without needing a mock transport (none is
 * injected -- both methods call `new MailService()` directly).
 *
 * That forced failure always goes through MailService::mail()'s own
 * `trigger_error(..., E_USER_WARNING)` (its calling condition,
 * `! (bool) ini_get('display_errors')`, is true in this CLI test process:
 * `display_errors` reads back as `''` here) -- phpunit.xml's
 * failOnWarning="true" would otherwise fail these tests on that expected,
 * production-code-deliberate warning, so every call that can reach a real
 * send is `@`-suppressed, matching ArrayHelperTest's established
 * `@unserialize()` pattern for the same failOnWarning interaction.
 *
 * incMailSentSuccess()'s own call sites inside
 * doSubscribeUnsubscribeNotificationByMail()/sendMailNotifications() (both
 * gated behind a real MailService::mail() returning true) are deliberately
 * not chased here: every other test in this class (and MailServiceTest's
 * own Integration suite) forces delivery *failure* via a closed local SMTP
 * port specifically because a deterministic *success* would need a real,
 * reachable mail transport, which no test anywhere in this codebase
 * attempts, by the same established design this class's own docblock
 * already documents for the failure trick.
 *
 * Both call sites construct `new MailService()` directly (no injectable
 * mailer, unlike MailService::mailGroup()'s own recipient-repo seam), so
 * there is no substitution point to force `mail()` to return true short of
 * standing up a real SMTP listener. The 5 lines this leaves uncovered
 * (NotificationByMailSender.php: 395, 659, 661, 662, 663) are exactly
 * incMailSentSuccess()'s 2 call sites plus sendMailNotifications()'s own
 * `$datas[] = [...]` success-branch append.
 */
final class NotificationByMailSenderTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationByMailSender $sender;

    private Connection $conn;

    /**
     * @var array{check_key: string, enabled: int, last_send: ?string}
     */
    private array $user1OriginalRow;

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
        // Every Lang::t() assertion in this file expects the real en_UK
        // admin.po wording (which sometimes differs from the raw English
        // literal passed to Lang::t() -- e.g. "No mail to send." vs the
        // po's "No mail to be sent."). Without this, whether admin.lang
        // happens to already be loaded (and by which language) depends on
        // which other Integration test file ran earlier in this shared
        // process. Loading it explicitly here makes every assertion
        // deterministic regardless of run order.
        LangTestFactory::get()->load('admin.lang');

        $conn = DbConnection::build();
        $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), ConfigRepository::class);
        $configService = new ConfigService($repo, CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        // sendMailNotifications()'s recent-post-dates block builds real
        // thumbnail URLs (NotificationService::getHtmlDescriptionRecentPostDate()
        // -> DerivativeImage::thumbUrl()) -- needs ImageStdParams loaded
        // (below) since IntegrationTestCase never runs a full
        // RequestBootstrap. DerivativeImage::urlService() resolves
        // UrlServiceInterface live from the container once Kernel is
        // booted, which this test already is via parent::setUp() -- no
        // explicit wiring needed here. Same real-config-row wiring as
        // CategoryDefaultRendererTest/CalendarMonthlyTest's own identical
        // setUp.
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->loadFromDb();

        $this->conn = $conn;
        $row = $this->conn->fetchAssociative(
            'SELECT check_key, enabled, last_send FROM user_mail_notification WHERE user_id = 1'
        );
        self::assertIsArray($row);
        // enabled is a genuine boolean column -- a raw (unmapped) fetch
        // returns a native PHP bool for it on Postgres, but a numeric 1/0
        // on MySQL, same as categories.visible/commentable elsewhere.
        // (int) normalizes either representation.
        $lastSend = $row['last_send'];
        $this->user1OriginalRow = [
            'check_key' => $row['check_key'],
            'enabled' => (int) $row['enabled'],
            'last_send' => $lastSend,
        ];

        $this->sender = PresentationAccessor::notificationByMailSender();
        PageStateTestFactory::get()->reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreUser1Row();
        // Idempotent even for tests that never touch user 4 -- belt-and-
        // suspenders cleanup so a failed assertion mid-test (which skips a
        // test-local finally-block restore) can't leak into later tests.
        $this->conn->executeStatement('DELETE FROM user_mail_notification WHERE user_id = 4');
        $this->conn->executeStatement('DELETE FROM user_auth_keys WHERE user_id = 4');
        $this->conn->executeStatement('UPDATE users SET mail_address = NULL WHERE id = 4');
        CurrentConfigTestFactory::get()->smtpHost = '';
        CurrentConfigTestFactory::get()->nbmListAllEnabledUsersToSend = false;
        CurrentConfigTestFactory::get()->nbmSendDetailedContent = true;
        PageStateTestFactory::get()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    private function fakeUser(): UserMailNotification
    {
        return new UserMailNotification(UserId::from(1), 'ck12345', 'fixture_admin', 'admin@example.test', true, null, 'normal');
    }

    /**
     * Rebuilds the DI container after lowering nbm_treatment_timeout_default
     * to a negative value -- (float) intval(ini_get('max_execution_time'))
     * is '0' under this CLI SAPI (confirmed via `php -r`), so
     * NotificationByMailSender's own constructor always falls back to this
     * config value for $sendmailTimeout; a negative default guarantees
     * checkSendmailTimeout()'s very first real-elapsed-time check is
     * already past it. Kernel::boot() itself no-ops once already booted
     * (this file's own setUp() already booted it), so reset() first is
     * required to force a fresh container -- and therefore a fresh
     * NotificationByMailSender reading the new config value at
     * construction time, since the already-built $this->sender instance
     * from setUp() captured the *old* value in its constructor.
     */
    private function senderWithImmediateTimeout(): NotificationByMailSender
    {
        Kernel::reset();
        // Kernel::reset() discards the real Paths setUp()'s own bare
        // Kernel::boot() call had silently reused from parent::setUp()'s
        // own default (idempotency-guarded) boot -- the fresh boot below
        // must supply Paths explicitly itself, or resolving Lang (reached
        // via Lang::load()'s own file lookup right below, and itself
        // requiring a real Paths constructor collaborator) fails against
        // this now-Paths-less container.
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        // Kernel::reset() also discards the container-shared Translator
        // instance setUp()'s own Lang::load('admin.lang') call populated --
        // Translator is rebuilt fresh per container, not a process-global
        // survivor -- without reloading it here, Lang::t() calls against
        // the fresh, empty Translator fall back to their raw untranslated
        // literal instead of the real po wording every assertion in this
        // file expects.
        LangTestFactory::get()->load('admin.lang');
        // Kernel::reset() also discards the container-shared CurrentUser
        // instance setUp()'s own attachGlobals() seed populated --
        // CurrentUser is rebuilt fresh per container, not a process-global
        // survivor -- without reseeding here, any
        // CurrentUserTestFactory::get()->get() reached from the fresh sender
        // (e.g. via AccessControl) throws "not initialised" against this
        // now-unseeded container.
        CurrentUserTestFactory::get()->attachGlobals();
        // Kernel::reset() also discards the container-shared CurrentConfig
        // instance -- CurrentConfig is rebuilt fresh per container too,
        // back to its own compiled-in defaults, not a process-global
        // survivor -- must be set here, on the fresh post-reboot instance,
        // not before the Kernel::reset() above, or the new container's own
        // fresh CurrentConfig would silently discard it and the rebuilt
        // sender below would read the ordinary default (20) instead of
        // this test's forced-immediate-timeout value.
        CurrentConfigTestFactory::get()->nbmTreatmentTimeoutDefault = -1;

        return PresentationAccessor::notificationByMailSender();
    }

    private function setUser1LastSend(?string $lastSend): void
    {
        $this->conn->executeStatement(
            'UPDATE user_mail_notification SET last_send = ? WHERE user_id = 1',
            [$lastSend]
        );
    }

    private function restoreUser1Row(): void
    {
        $this->conn->executeStatement(
            'UPDATE user_mail_notification SET enabled = ?, last_send = ? WHERE user_id = 1',
            [$this->user1OriginalRow['enabled'], $this->user1OriginalRow['last_send']]
        );
    }

    private function user1Enabled(): int
    {
        $value = $this->conn->fetchOne(
            'SELECT enabled FROM user_mail_notification WHERE user_id = 1'
        );

        return (int) $value;
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

    public function testIncMailSentSuccessRecordsAnInfoMessage(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());

        self::assertSame(
            ['Mail sent to fixture_admin [admin@example.test].'],
            PageStateTestFactory::get()->infos
        );
    }

    public function testIncMailSentFailedRecordsAnErrorMessage(): void
    {
        $this->sender->incMailSentFailed($this->fakeUser());

        self::assertSame(
            ['Error when sending email to fixture_admin [admin@example.test].'],
            PageStateTestFactory::get()->errors
        );
    }

    public function testDisplayCounterInfoReportsNoMailToSendWhenNothingWasAttempted(): void
    {
        $this->sender->displayCounterInfo();

        self::assertSame(['No mail to be sent.'], PageStateTestFactory::get()->infos);
        self::assertSame([], PageStateTestFactory::get()->errors);
    }

    public function testDisplayCounterInfoReportsOnlyASuccessCountWhenNothingFailed(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());
        PageStateTestFactory::get()->reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has been sent.'], PageStateTestFactory::get()->infos);
        self::assertSame([], PageStateTestFactory::get()->errors);
    }

    public function testDisplayCounterInfoReportsBothFailureAndSuccessCountsWhenSomeSucceeded(): void
    {
        $this->sender->incMailSentSuccess($this->fakeUser());
        $this->sender->incMailSentFailed($this->fakeUser());
        PageStateTestFactory::get()->reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has not been sent.'], PageStateTestFactory::get()->errors);
        self::assertSame(['1 mail has been sent.'], PageStateTestFactory::get()->infos);
    }

    public function testDisplayCounterInfoReportsOnlyAFailureCountWhenNothingSucceeded(): void
    {
        $this->sender->incMailSentFailed($this->fakeUser());
        PageStateTestFactory::get()->reset();

        $this->sender->displayCounterInfo();

        self::assertSame(['1 mail has not been sent.'], PageStateTestFactory::get()->errors);
        self::assertSame([], PageStateTestFactory::get()->infos);
    }

    public function testNbmMailContentFieldsBuildsTheSharedViewFieldSet(): void
    {
        // nbmMailContentFields() is private (Renderer/View conversion
        // turned it from an ambient assignContext() write into a pure,
        // Reflection-testable return value -- same established pattern
        // as MailService::resolveMailTheme()/emptyValue()) -- this
        // exercises the full UrlService full-url toggling +
        // addUrlParams() chain end to end and asserts the real returned
        // values, not just "no fatal."
        $method = new ReflectionMethod(NotificationByMailSender::class, 'nbmMailContentFields');

        /** @var array{username: string, sendAsName: ?string, unsubscribeLink: string, subscribeLink: string, contactEmail: ?string} $fields */
        $fields = $method->invoke($this->sender, $this->fakeUser());

        self::assertSame('fixture_admin', $fields['username']);
        // beginUsersEnv() was never called here, so sendAsName/contactEmail
        // stay at their un-initialised null default.
        self::assertNull($fields['sendAsName']);
        self::assertNull($fields['contactEmail']);
        self::assertStringContainsString('unsubscribe=ck12345', $fields['unsubscribeLink']);
        self::assertStringContainsString('subscribe=ck12345', $fields['subscribeLink']);
    }

    public function testSendMailNotificationsReturnsAnEmptyListForAnUnrecognisedAction(): void
    {
        self::assertSame([], $this->sender->sendMailNotifications('not_a_real_action'));
    }

    public function testSendMailNotificationsSendActionReportsNoUserWhenTheCheckKeyListMatchesNothing(): void
    {
        $result = $this->sender->sendMailNotifications('send', ['this-check-key-does-not-exist']);

        self::assertSame([], $result);
        self::assertSame(['No user to be notified by mail.'], PageStateTestFactory::get()->errors);
    }

    public function testSendMailNotificationsListToSendReturnsOnlyUsersWithPendingNews(): void
    {
        // Far enough in the past that every fixture photo (date_available
        // 2026-08-01, see NotificationServiceTest's own confirmed
        // newsExists() boundary) counts as "news" since that last_send.
        $this->setUser1LastSend('2000-01-01 00:00:00');

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertCount(1, $result);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
        self::assertSame([], PageStateTestFactory::get()->errors);
    }

    public function testSendMailNotificationsListToSendExcludesAUserWithNoPendingNews(): void
    {
        // A last_send at (not before) the fixed test clock leaves no room
        // for any fixture photo to count as "new" -- the inverse fixture
        // shape from the "pending news" test above.
        $this->setUser1LastSend('2026-08-01 00:00:00');

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertSame([], $result);
    }

    public function testSendMailNotificationsListToSendReturnsTheRawListUnfilteredWhenQuickListIsEnabled(): void
    {
        // Quick-list mode (nbm_list_all_enabled_users_to_send) skips the
        // per-user newsExists() check entirely -- confirmed by pairing it
        // with a last_send that would otherwise exclude the user (see the
        // "excludes a user with no pending news" test above).
        $this->setUser1LastSend('2026-08-01 00:00:00');
        CurrentConfigTestFactory::get()->nbmListAllEnabledUsersToSend = true;

        $result = $this->sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertCount(1, $result);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
    }

    public function testSendMailNotificationsSendActionRecordsAFailedMailAndTreatsTheCheckKeyOnDeliveryFailure(): void
    {
        $this->setUser1LastSend('2000-01-01 00:00:00');
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';

        // A forced delivery failure always raises MailService::mail()'s
        // own deliberate E_USER_WARNING in this CLI process, which
        // failOnWarning="true" would otherwise turn into a test failure.
        $result = $this->suppressMailerWarning(fn (): array => $this->sender->sendMailNotifications('send', [$this->user1OriginalRow['check_key']]));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        // incMailSentFailed() (inside the per-user loop) then
        // displayCounterInfo() (after the loop) both write to `errors` --
        // displayCounterInfo() only ever adds to `infos` when at least one
        // mail also succeeded (see the already-covered
        // test_displayCounterInfo_reports_only_a_failure_count_when_nothing_succeeded).
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                '1 mail has not been sent.',
            ],
            PageStateTestFactory::get()->errors
        );
        self::assertSame([], PageStateTestFactory::get()->infos);
    }

    public function testSendMailNotificationsSendActionUsesTheNewsExistsOnlyBranchWhenDetailedContentIsDisabled(): void
    {
        $this->setUser1LastSend('2000-01-01 00:00:00');
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';
        CurrentConfigTestFactory::get()->nbmSendDetailedContent = false;

        $result = $this->suppressMailerWarning(fn (): array => $this->sender->sendMailNotifications('send', [$this->user1OriginalRow['check_key']]));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                '1 mail has not been sent.',
            ],
            PageStateTestFactory::get()->errors
        );
    }

    public function testFindAvailableCheckKeyReturnsAKeyNotAlreadyTaken(): void
    {
        $key = $this->sender->findAvailableCheckKey();

        self::assertNotSame($this->user1OriginalRow['check_key'], $key);
        $taken = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM user_mail_notification WHERE check_key = ?',
            [$key]
        );
        self::assertSame(0, $taken);
    }

    public function testGetUserNotificationsDelegatesToTheNotificationByMailService(): void
    {
        $result = $this->sender->getUserNotifications('subscribe', [$this->user1OriginalRow['check_key']], '');

        self::assertCount(1, $result);
        self::assertSame($this->user1OriginalRow['check_key'], $result[0]->checkKey);
        self::assertSame('fixture_admin', $result[0]->username);
    }

    public function testCheckSendmailTimeoutAndStartTimeReflectAFreshlyConstructedSender(): void
    {
        // A freshly-constructed sender's own $startTime is effectively
        // "now" (Piwigo\Core\TimingHelper::getMoment(), a real wall-clock
        // read, not the frozen PIWIGO_TEST_NOW), so an immediate
        // checkSendmailTimeout() call is always well within the timeout
        // window -- CurrentConfig::nbmTreatmentTimeoutDefault()'s default
        // is measured in whole seconds.
        self::assertGreaterThan(0.0, $this->sender->startTime());
        self::assertFalse($this->sender->isSendmailTimeout());

        $isTimeout = $this->sender->checkSendmailTimeout();

        self::assertFalse($isTimeout);
        self::assertFalse($this->sender->isSendmailTimeout());
    }

    public function testBeginUsersEnvUsesTheConfiguredNbmSendMailAsNameInsteadOfTheMailSenderNameFallback(): void
    {
        // sendAsName has no public getter, and MailService::mail() is
        // never actually reached here -- this exercises nbm_send_mail_as's
        // own ternary true branch (an admin-configured override) rather
        // than falling through to MailService::getMailSenderName(), for a
        // private, otherwise unobservable field. Not
        // expectNotToPerformAssertions(): this file's own setUp() already
        // performs real assertions, which PHPUnit counts against every
        // test in the class.
        CurrentConfigTestFactory::get()->nbmSendMailAs = 'Custom Notifier';

        $this->sender->beginUsersEnv(true);
        $this->sender->endUsersEnv();
    }

    public function testDoSubscribeUnsubscribeNotificationByMailIgnoresAnEmptyCheckKeyList(): void
    {
        $result = $this->sender->doSubscribeUnsubscribeNotificationByMail(true, false, []);

        self::assertSame([], $result);
        self::assertSame(['0 users updated.'], PageStateTestFactory::get()->infos);
        self::assertSame([], PageStateTestFactory::get()->errors);
    }

    public function testDoSubscribeUnsubscribeNotificationByMailRecordsAFailedMailAndLeavesEnabledUnchangedOnDeliveryFailure(): void
    {
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';
        self::assertSame(1, $this->user1Enabled());

        $result = $this->suppressMailerWarning(fn (): array => $this->sender->doSubscribeUnsubscribeNotificationByMail(
            true,
            false,
            [$this->user1OriginalRow['check_key']]
        ));

        self::assertSame([$this->user1OriginalRow['check_key']], $result);
        // Four independent addError() call sites all fire for this one
        // failed attempt, in call order: incMailSentFailed() inside the
        // loop, the loop's own $doUpdate===false branch, displayCounterInfo()
        // after the loop (mirrors sendMailNotifications' identical
        // "errors only, no infos, on an all-failure batch" shape -- see
        // test_displayCounterInfo_reports_only_a_failure_count_when_nothing_succeeded),
        // and the final $errorOnUpdatedDataCount!==0 summary.
        self::assertSame(
            [
                'Error when sending email to fixture_admin [fixture_admin@example.test].',
                'User fixture_admin [fixture_admin@example.test] not removed from the subscription list.',
                '1 mail has not been sent.',
                '1 user not updated.',
            ],
            PageStateTestFactory::get()->errors
        );
        self::assertSame(['0 users updated.'], PageStateTestFactory::get()->infos);
        // $doUpdate stayed false on delivery failure, so the batched
        // massUpdate() never included this row -- enabled is untouched.
        self::assertSame(1, $this->user1Enabled());
    }

    public function testDoSubscribeUnsubscribeNotificationByMailStopsTheLoopEarlyWhenTheSendmailTimeoutIsAlreadyExceeded(): void
    {
        $sender = $this->senderWithImmediateTimeout();

        $result = $sender->doSubscribeUnsubscribeNotificationByMail(true, false, [$this->user1OriginalRow['check_key']]);

        // The timeout check runs before the check_key is even added to
        // $checkKeyTreated, so the very first (and only) user breaks the
        // loop without being treated at all.
        self::assertSame([], $result);
        self::assertSame(
            ['The time to send mail is limited. Others mails have been skipped.'],
            PageStateTestFactory::get()->errors
        );
        // displayCounterInfo() runs unconditionally after the loop (even
        // though the loop broke on its very first iteration, before
        // treating any user) -- with nothing sent and nothing failed, its
        // own "no mail to send" info message lands first, then the final
        // updated-count summary below it.
        self::assertSame(['No mail to be sent.', '0 users updated.'], PageStateTestFactory::get()->infos);
    }

    public function testSendMailNotificationsListToSendStopsEarlyWhenTheSendmailTimeoutIsAlreadyExceeded(): void
    {
        $sender = $this->senderWithImmediateTimeout();
        $this->setUser1LastSend('2000-01-01 00:00:00');

        $result = $sender->sendMailNotifications('list_to_send', [$this->user1OriginalRow['check_key']]);

        self::assertSame([], $result);
        self::assertSame(
            ['The time to prepare the list of users who will be sent mail is limited. Other users are not listed.'],
            PageStateTestFactory::get()->infos
        );
        self::assertSame([], PageStateTestFactory::get()->errors);
    }

    public function testSendMailNotificationsSendActionStopsEarlyWhenTheSendmailTimeoutIsAlreadyExceeded(): void
    {
        $sender = $this->senderWithImmediateTimeout();
        $this->setUser1LastSend('2000-01-01 00:00:00');

        $result = $sender->sendMailNotifications('send', [$this->user1OriginalRow['check_key']]);

        self::assertSame([], $result);
        self::assertSame(
            ['The time to send mail is limited. Others mails have been skipped.'],
            PageStateTestFactory::get()->errors
        );
        // displayCounterInfo() still runs after the loop for a 'send'
        // action regardless of the early break (0 sent, 0 failed).
        self::assertSame(['No mail to be sent.'], PageStateTestFactory::get()->infos);
    }

    public function testSendMailNotificationsSendActionBuildsAnAuthKeyLinkAndANeverSentBeforeAndCustomContentMailForAnEligibleUser(): void
    {
        // User 4 (power_user, status 'normal' -- unlike user 1's
        // 'webmaster', which AuthService::createUserAuthKey() always
        // refuses) gets a temporary, real notification row + mail_address
        // so it both (a) qualifies for a real auth_key
        // (authKey !== false, the LINK-building branch), and (b) has
        // never been sent to before (last_send NULL, the "single date"
        // branch -- every other test's setUser1LastSend() only ever
        // exercises the "between two dates" branch). Also sets a real
        // nbm_complementary_mail_content so the per-user
        // customize-content branch (never empty here, no plugin handler
        // registered) fires too.
        $this->conn->executeStatement("UPDATE users SET mail_address = 'temp4@example.test' WHERE id = 4");
        // check_key is varchar(16) -- must stay within that limit.
        $checkKey = 'user4-tmp-key';
        // enabled is a genuine boolean column -- a bare `1` literal in the
        // SQL text itself (unlike a bound parameter, which the driver
        // coerces implicitly) is rejected outright by Postgres ("column
        // ... is of type boolean but expression is of type integer").
        $enabledLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement(
            "INSERT INTO user_mail_notification (user_id, check_key, enabled, last_send) VALUES (?, ?, {$enabledLiteral}, NULL)",
            [4, $checkKey]
        );
        CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:1';
        CurrentConfigTestFactory::get()->nbmComplementaryMailContent = 'A note from the admin.';

        $result = $this->suppressMailerWarning(fn (): array => $this->sender->sendMailNotifications('send', [$checkKey]));

        self::assertSame([$checkKey], $result);
        self::assertSame(
            [
                'Error when sending email to power_user [temp4@example.test].',
                '1 mail has not been sent.',
            ],
            PageStateTestFactory::get()->errors
        );

        $authKeyCount = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM user_auth_keys' . " WHERE user_id = 4 AND key_type = 'auth_key'"
        );
        self::assertSame(1, $authKeyCount);
    }
}
