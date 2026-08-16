<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Mail\MailService;
use Piwigo\Mail\Projection\NbmMailContentPageContext;
use Piwigo\Mail\Projection\NbmSubscribeActionMailContext;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\MailServiceTestSpyTransport;
use Piwigo\Tests\Support\MailServiceTestTransportSwap;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * Golden-content coverage for the 13 real mail/*.latte templates
 * (themes/default/template/mail/text/{html,plain}/*), never reachable via
 * an HTTP route -- MailService::mail() renders them internally
 * ($template->parse(...), same Latte pipeline as every page template) and
 * hands the result straight to Symfony Mailer, so
 * GoldenHtmlSnapshotTest.php's curl-a-route mechanism can't reach them.
 *
 * Interception/bootstrap pattern copied directly from this suite's own
 * MailServiceTest.php (mailCaptureBeforeSend()): a MailServiceTestSpyTransport,
 * swapped into $this->mailer's own MailService::$transportOverride via
 * MailServiceTestTransportSwap, sits one level below Symfony's own Mailer
 * and captures the fully-rendered Symfony Mime\Email (real subject/HTML
 * body/text body already set -- header, footer, and (for text/html) the
 * global/theme CSS partials all already spliced in) instead of a real
 * Transport::send() call. Every step before it (all real template
 * rendering) still runs for real. Deliberately NOT the MailerInterface-swap
 * technique ApiKeyServiceLifecycleTestSpyMailer/
 * CommentServiceFakeMailerRecordsNotifications use elsewhere in this suite
 * -- those spies REPLACE MailService::mail() outright (its own rendering
 * never runs), which is right for verifying "was mail() called with the
 * right args" but wrong here, where the rendered content itself is what's
 * under test.
 *
 * 3 real content templates exist (cat_group_info, notification_admin,
 * notification_by_mail -- each with an html+plain pair), plus mail_header/
 * mail_footer (wrapped around every send, both content types) and 3
 * theme-CSS-only html partials (global-mail-css, mail-css-clear,
 * mail-css-dark) = 13 files total. 4 real sends close all of them:
 *  1. cat_group_info (theme=clear, AlbumNotificationPageRenderer's own
 *     real $args/$tpl shape) -- also closes header/footer/global-css/
 *     mail-css-clear.
 *  2. mailNotificationAdmins() -- notification_admin, MailService's own
 *     real caller shape (fixture's mail_theme config is 'clear', already
 *     closed by #1, so no theme collision).
 *  3. notification_by_mail -- NotificationByMailSender's own real shape:
 *     the caller pre-renders via MailService::getMailTemplate()->parse(),
 *     then passes the string as args['content'] with no tpl filename at
 *     all (replicated here directly rather than driving the full
 *     subscribe/unsubscribe orchestration, which needs NBM-specific
 *     fixture state unrelated to what this test verifies).
 *  4. A bare mail() call forcing theme=dark, purely to reach
 *     mail-css-dark.latte -- no other real caller ever sets a non-default
 *     theme, so this one send exists only for coverage, not to mirror a
 *     specific caller.
 */
final class MailGoldenHtmlSnapshotTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private MailService $mailer;

    private MailServiceTestSpyTransport $mailerSpy;

    private string $rootUrl;

    private string $absoluteRootUrl;

    private ?string $previousHttpHost = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        // MailService::mail() computes GALLERY_URL via
        // UrlService::getAbsoluteRootUrl(), which reads the live
        // $_SERVER['HTTP_HOST'] -- absent in a CLI/Pest process, and (per
        // ExtensionLifecycleTest's own docblock on the same quirk)
        // sometimes left over from whichever earlier Integration test file
        // happened to run first in this shared process (e.g.
        // InstallWizardTest sets it to 'example.test' and never restores
        // it). Left unpinned, this golden snapshot's GALLERY_URL link would
        // vary by test run order/worker instead of reflecting real
        // application output -- pin it here the same way
        // ExtensionLifecycleTest already does for its own, different
        // reason.
        $previousHttpHost = $_SERVER['HTTP_HOST'] ?? null;
        $this->previousHttpHost = is_string($previousHttpHost) ? $previousHttpHost : null;
        $_SERVER['HTTP_HOST'] = 'mail-golden-html-test.invalid';

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
        // Every real send in this file goes through the two
        // *CaptureBeforeSend() helpers below -- wiring the spy once here,
        // rather than per-call, matches this class's own docblock claim
        // that all 4 sends are deliberate golden-content coverage, never a
        // genuine network attempt. Replaces the old `BeforeSendMail`
        // event-hook interception (P32 Stage A5 found that event had zero
        // production listeners).
        $this->mailerSpy = new MailServiceTestSpyTransport();
        $this->mailer = MailServiceTestTransportSwap::with($mailer, $this->mailerSpy);

        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        self::assertInstanceOf(UrlServiceInterface::class, $urlService);
        $this->rootUrl = $urlService->getRootUrl();

        // getAbsoluteRootUrl() also folds in Auth\CookieService::cookiePath(),
        // which falls back to dirname($_SERVER['SCRIPT_NAME']) with no
        // REDIRECT_SCRIPT_NAME/REDIRECT_URL present (true for a CLI/Pest
        // process) -- pinning HTTP_HOST above doesn't make this half
        // deterministic across different invocation styles (composer
        // script vs. direct `vendor/bin/pest` vs. an absolute path). Same
        // fix as $rootUrl below: capture it once here (same $_SERVER state
        // MailService::mail()'s own internal getAbsoluteRootUrl() call
        // will see) and normalize it out of the golden comparison instead
        // of trying to force it to a fixed value.
        $this->absoluteRootUrl = $urlService->getAbsoluteRootUrl();
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->previousHttpHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->previousHttpHost;
        }

        Kernel::reset();
        parent::tearDown();
    }

    /**
     * Same technique as MailServiceTest::mailCaptureBeforeSend() --
     * $this->mailer was already built with $this->mailerSpy in setUp(), so
     * this just reads the newest captured Email back off it.
     *
     * @param string|array<int|string, mixed> $to
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl
     */
    private function mailCaptureBeforeSend(string|array $to, array $args = [], array $tpl = []): Email
    {
        $countBefore = count($this->mailerSpy->sent);

        $this->mailer->mail($to, $args, $tpl);

        if (! array_key_exists($countBefore, $this->mailerSpy->sent)) {
            throw new RuntimeException('expected mail() to have sent through the spy transport');
        }

        return $this->mailerSpy->sent[$countBefore];
    }

    /**
     * Same technique, for mailNotificationAdmins() -- a distinct public
     * method (not mail() itself), so it needs its own thin wrapper.
     *
     * @param string|array{key_args: array<int, mixed>} $subject
     * @param string|list<array{key_args: array<int, mixed>}> $content
     */
    private function mailNotificationAdminsCaptureBeforeSend(string|array $subject, string|array $content): Email
    {
        $countBefore = count($this->mailerSpy->sent);

        $this->mailer->mailNotificationAdmins($subject, $content);

        if (! array_key_exists($countBefore, $this->mailerSpy->sent)) {
            throw new RuntimeException('expected mailNotificationAdmins() to have sent through the spy transport (no admin/webmaster recipient in the fixture?)');
        }

        return $this->mailerSpy->sent[$countBefore];
    }

    public function testCapturesEveryMailTemplate(): void
    {
        $emails = [];

        $emails['cat-group-info'] = $this->mailCaptureBeforeSend(
            [
                'name' => 'Fixture Recipient',
                'email' => 'golden-html-recipient@example.test',
            ],
            [
                'subject' => '[Fixture Gallery] Visit album Sample Album',
                'theme' => 'clear',
            ],
            [
                'filename' => 'cat_group_info',
                'assign' => [
                    'IMG' => [],
                    'CAT_NAME' => 'Sample Album',
                    'LINK' => $this->rootUrl . 'index.php?/category/1',
                    'CPL_CONTENT' => 'A short note about this album.',
                ],
            ]
        );

        $emails['notification-admin'] = $this->mailNotificationAdminsCaptureBeforeSend(
            '[Fixture Gallery] New comment awaiting validation',
            'A new comment is waiting for validation.',
        );

        // Template::setFilename() (the old Smarty-era 2-arg
        // handle-name-to-real-file registration) doesn't exist on this
        // rewrite's own Template class at all -- parse() already takes a
        // real file directly, matching MailService::
        // resolveMailTemplateFilename()'s own "prefer .latte, fall back to
        // .tpl" convention every other real caller here goes through.
        $mailTemplate = $this->mailer->getMailTemplate('text/html');
        // Real production callers (NotificationByMailSender::
        // doSubscribeUnsubscribeNotificationByMail()) always assign
        // NbmMailContentPageContext (USERNAME/SEND_AS_NAME/etc.) before
        // NbmSubscribeActionMailContext -- notification_by_mail.latte's
        // own {$USERNAME} read needs the first context too, not just the
        // second one this test originally assigned alone.
        $mailTemplate->assignContext(new NbmMailContentPageContext(
            username: 'Fixture Recipient',
            sendAsName: 'Fixture Gallery',
            unsubscribeLink: $this->rootUrl . 'nbm.php?unsubscribe=fixture-check-key',
            subscribeLink: $this->rootUrl . 'nbm.php?subscribe=fixture-check-key',
            contactEmail: 'fixture_admin@example.test',
        ));
        $mailTemplate->assignContext(new NbmSubscribeActionMailContext(
            sectionActionBy: 'subscribe_by_himself',
            galleryTitle: 'Fixture Gallery',
            galleryUrl: $this->rootUrl,
        ));
        $emails['notification-by-mail'] = $this->mailCaptureBeforeSend(
            [
                'name' => 'Fixture Recipient',
                'email' => 'golden-html-nbm@example.test',
            ],
            [
                'subject' => '[Fixture Gallery] Subscribe to notification by mail',
                'email_format' => 'text/html',
                'content' => $mailTemplate->parse('notification_by_mail.latte', true),
                'content_format' => 'text/html',
            ]
        );

        $emails['dark-theme-css'] = $this->mailCaptureBeforeSend(
            [
                'name' => 'Fixture Recipient',
                'email' => 'golden-html-dark-theme@example.test',
            ],
            [
                'subject' => '[Fixture Gallery] Dark theme CSS coverage',
                'theme' => 'dark',
            ]
        );

        foreach ($emails as $name => $email) {
            $htmlBody = $email->getHtmlBody();
            $textBody = $email->getTextBody();

            self::assertIsString($htmlBody, "{$name} has no HTML body");
            self::assertIsString($textBody, "{$name} has no text body");

            $this->assertMailGolden("{$name}-html", $htmlBody);
            $this->assertMailGolden("{$name}-plain", $textBody);
        }
    }

    private function mailGoldenDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/GoldenHtml/mail';
    }

    /**
     * Same shape as GoldenHtmlSnapshotTest.php's goldenHtmlAssertOrWrite() --
     * a route with no existing baseline writes one, GOLDEN_HTML_UPDATE=1
     * overwrites deliberately, otherwise a byte-exact compare against the
     * committed baseline. Kept as its own small copy rather than requiring
     * the Browser test file directly: this suite's convention (see
     * ApiKeyServiceLifecycleTest.php et al.) is plain PHPUnit assertions,
     * not Pest's expect() the Browser helper is built on, and mail content
     * needs a different (much smaller) normalization set -- the relative
     * and absolute root URLs, both known here directly rather than
     * detected from anchor tags the way the HTML normalizer has to.
     */
    private function assertMailGolden(string $name, string $body): void
    {
        $normalized = str_replace($this->absoluteRootUrl, '{{ABSOLUTE_ROOT_URL}}', $body);
        $normalized = str_replace($this->rootUrl, '{{ROOT_URL}}', $normalized);

        $dir = $this->mailGoldenDir();
        $path = $dir . '/' . $name . '.txt';

        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (! file_exists($path)) {
            file_put_contents($path, $normalized);

            return;
        }

        $existing = file_get_contents($path);
        self::assertIsString($existing);

        if (getenv('GOLDEN_HTML_UPDATE') === '1') {
            file_put_contents($path, $normalized);

            return;
        }

        if ($normalized === $existing) {
            return;
        }

        $diffDir = $dir . '/.diffs';
        if (! is_dir($diffDir)) {
            mkdir($diffDir, 0o755, true);
        }

        $freshPath = tempnam(sys_get_temp_dir(), 'mail-golden-fresh-');
        $existingPath = tempnam(sys_get_temp_dir(), 'mail-golden-existing-');
        self::assertIsString($freshPath);
        self::assertIsString($existingPath);
        file_put_contents($freshPath, $normalized);
        file_put_contents($existingPath, $existing);
        $diff = shell_exec('diff -u ' . escapeshellarg($existingPath) . ' ' . escapeshellarg($freshPath));
        unlink($freshPath);
        unlink($existingPath);
        file_put_contents($diffDir . '/' . $name . '.diff', is_string($diff) ? $diff : '');

        self::fail("{$name}'s golden content changed. Review the diff at {$diffDir}/{$name}.diff; if intentional, re-run with GOLDEN_HTML_UPDATE=1.");
    }
}
