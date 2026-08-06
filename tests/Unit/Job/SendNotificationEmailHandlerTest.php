<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Mail\BeforeSendMail;
use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;

// P23 batch 8f-4: the get_webmaster_mail_address() function stub is gone
// (free function deleted with include/functions.inc.php; MailService now
// reaches the webmaster address through its optional
// WebmasterMailProviderInterface constructor param). No fake is needed
// here: the empty-$to job below short-circuits MailService::mail() to
// `return true` before getMailConfiguration()/the webmaster lookup ever
// runs (verified against mail()'s own first guard).

/**
 * Every MailService::__construct() shim collaborator (singleton/
 * service-locator elimination campaign, Phase 11 sub-phase 11E), resolved
 * from a real booted container -- Kernel::boot() is idempotent, so calling
 * it here is a safe no-op when the caller already booted its own Kernel.
 */
function send_notification_email_handler_test_mail_service(): MailService
{
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));

    $lang = Kernel::container()->get(Lang::class);
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    $deploymentPolicy = Kernel::container()->get(DeploymentPolicy::class);
    $pageState = Kernel::container()->get(PageState::class);
    $paths = Kernel::container()->get(Paths::class);
    $sessionService = Kernel::container()->get(SessionService::class);
    $translator = Kernel::container()->get(Translator::class);
    $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
    $currentUser = Kernel::container()->get(CurrentUser::class);
    $urlService = Kernel::container()->get(UrlServiceInterface::class);
    if (! $lang instanceof Lang || ! $currentConfig instanceof CurrentConfig || ! $deploymentPolicy instanceof DeploymentPolicy
        || ! $pageState instanceof PageState || ! $paths instanceof Paths || ! $sessionService instanceof SessionService
        || ! $translator instanceof Translator || ! $eventDispatcher instanceof EventDispatcher
        || ! $currentUser instanceof CurrentUser || ! $urlService instanceof UrlServiceInterface
    ) {
        throw new LogicException('Container returned an unexpected type');
    }

    return new MailService($lang, $currentConfig, $deploymentPolicy, $pageState, $paths, $sessionService, $translator, $eventDispatcher, $currentUser, $urlService);
}

test('__invoke delegates to MailService::mail with the job to/args/tpl', function (): void {
    // An empty $to short-circuits MailService::mail() to `return true`
    // before it ever touches a real Transport (see its own emptyValue($to)
    // guard) -- same "don't stub/exercise what would kill the test"
    // reasoning used throughout this suite for a genuinely side-effecting
    // free function this project has no test double for.
    $handler = new SendNotificationEmailHandler(send_notification_email_handler_test_mail_service());

    $handler(new SendNotificationEmailJob(to: ''));
})->throwsNoExceptions();

test('__invoke actually reaches MailService::mail() with the job\'s exact to/args, not just avoids throwing', function (): void {
    // Kills mail()'s own RemoveMethodCall inside __invoke() -- the
    // sibling test above uses an empty $to specifically so mail() never
    // gets past its own first guard, which makes it indistinguishable
    // from the call being removed outright (both produce zero
    // observable side effects). A non-empty $to pushes mail() past that
    // guard for real; the 'before_send_mail' hook fires with the exact
    // job arguments right before the real Transport would be touched,
    // and returning false from it here stops mail() one line short of
    // that real send -- this environment's own real sendmail_path
    // config means letting it proceed further would attempt a genuine
    // delivery, not a test double.
    // The real project root, not a throwaway temp dir -- getMailTemplate()
    // needs the real themes/default/template/mail/text/plain/*.tpl files
    // to actually parse (Smarty throws "Unable to load" otherwise), and
    // fabricating stub templates would test Smarty's own file-loading
    // rather than this handler's delegation. Same real-root pattern
    // already used by ErrorCollectorTest/ShutdownHandlerTest/
    // MessengerFactoryTest/ContainerDetectorTest. Booted BEFORE the
    // CurrentConfig writes below so they land on the real container-shared
    // instance MailService::mail() itself resolves via CurrentConfig::
    // current() -- the pre-boot fallback is a different, memoized object
    // (same "seed after boot, not before" fix shape as every other
    // Current* wrapper in this campaign, e.g.
    // RequestBootstrapBootConfigOnlyTest.php's own "reuses an
    // already-set CurrentConfigService" test).
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));

    CurrentConfig::current()->setMailSenderEmail('sender@example.test');
    CurrentConfig::current()->setMailSenderName('Test Sender');
    // Skips the real theme's text/html mail templates -- header.tpl
    // there reads lang_info['code'] directly, which needs a real
    // Lang::load() to populate; the plain-text template mail() always
    // also renders doesn't touch lang_info at all, and is sufficient to
    // prove the real render+send pipeline genuinely ran.
    CurrentConfig::current()->setMailAllowHtml(false);
    // Skips Template::__construct()'s own one-time data_dir_checked
    // write (which otherwise needs a real, activated
    // CurrentConfigService -- more bootstrap than this Unit test
    // should need just to prove delegation).
    CurrentConfig::current()->setDataDirChecked('1');

    $capturedTo = null;
    $capturedArgs = null;
    $eventHandler = function (BeforeSendMail $event) use (&$capturedTo, &$capturedArgs): BeforeSendMail {
        $capturedTo = $event->to;
        $capturedArgs = $event->args;

        return new BeforeSendMail(false, $event->to, $event->args, $event->email);
    };
    EventDispatcherTestFactory::get()->addTypedHandler(BeforeSendMail::class, $eventHandler);

    try {
        $handler = new SendNotificationEmailHandler(send_notification_email_handler_test_mail_service());

        $handler(new SendNotificationEmailJob(to: 'someone@example.test', args: ['subject' => 'Test Subject']));

        expect($capturedTo)->toBe('someone@example.test');
        if ($capturedArgs === null) {
            throw new RuntimeException('expected the before_send_mail handler to have captured real args');
        }
        expect($capturedArgs['subject'] ?? null)->toBe('Test Subject');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(BeforeSendMail::class, $eventHandler);
        CurrentConfig::current()->reset();
        Kernel::reset();
    }
});
