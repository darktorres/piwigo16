<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Event\Mail\BeforeSendMail;
use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;

// P23 batch 8f-4: the get_webmaster_mail_address() function stub is gone
// (free function deleted with include/functions.inc.php; MailService now
// reaches the webmaster address through its optional
// WebmasterMailProviderInterface constructor param). No fake is needed
// here: the empty-$to job below short-circuits MailService::mail() to
// `return true` before getMailConfiguration()/the webmaster lookup ever
// runs (verified against mail()'s own first guard).

test('__invoke delegates to MailService::mail with the job to/args/tpl', function (): void {
    // An empty $to short-circuits MailService::mail() to `return true`
    // before it ever touches a real Transport (see its own emptyValue($to)
    // guard) -- same "don't stub/exercise what would kill the test"
    // reasoning used throughout this suite for a genuinely side-effecting
    // free function this project has no test double for.
    $handler = new SendNotificationEmailHandler(new MailService());

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
    \Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3) . '/'));

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
    EventDispatcher::get()->addTypedHandler(BeforeSendMail::class, $eventHandler);

    try {
        $handler = new SendNotificationEmailHandler(new MailService());

        $handler(new SendNotificationEmailJob(to: 'someone@example.test', args: ['subject' => 'Test Subject']));

        expect($capturedTo)->toBe('someone@example.test');
        if ($capturedArgs === null) {
            throw new RuntimeException('expected the before_send_mail handler to have captured real args');
        }
        expect($capturedArgs['subject'] ?? null)->toBe('Test Subject');
    } finally {
        EventDispatcher::get()->removeEventHandler(BeforeSendMail::class, $eventHandler);
        \Piwigo\Config\CurrentConfig::current()->reset();
        \Piwigo\Core\Kernel::reset();
    }
});
