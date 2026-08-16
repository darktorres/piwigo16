<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\MailServiceTestSpyTransport;
use Piwigo\Tests\Support\MailServiceTestTransportSwap;
use Symfony\Component\Mime\Address;

// MailService reaches the webmaster address through its
// WebmasterMailProviderInterface constructor collaborator. No fake is
// needed here: the empty-$to job below short-circuits MailService::mail()
// to `return true` before getMailConfiguration()/the webmaster lookup
// ever runs (verified against mail()'s own first guard).

/**
 * MailService is fully container-resolvable (every constructor
 * collaborator either bound or autowireable) -- resolve the real
 * container-shared instance instead of hand-reconstructing it from its
 * own collaborators. Kernel::boot() is idempotent, so calling it here is
 * a safe no-op when the caller already booted its own Kernel.
 */
function send_notification_email_handler_test_mail_service(): MailService
{
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));

    $mailService = Kernel::container()->get(MailService::class);
    if (! $mailService instanceof MailService) {
        throw new LogicException('Container returned an unexpected type for ' . MailService::class);
    }

    return $mailService;
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
    // guard for real; a MailServiceTestSpyTransport (swapped in via
    // MailServiceTestTransportSwap, replacing the old 'before_send_mail'
    // event-hook interception -- P32 Stage A5 found that event had zero
    // production listeners) captures the fully-built Email one step
    // before a real Transport::send() would run -- this environment's
    // own real sendmail_path config means letting it proceed further
    // would attempt a genuine delivery, not a test double.
    // The real project root, not a throwaway temp dir -- getMailTemplate()
    // needs the real themes/default/template/mail/text/plain/*.latte files
    // to actually render (Latte fatal-errors otherwise), and fabricating
    // stub templates would test Latte's own file-loading rather than this
    // handler's delegation. Same real-root pattern
    // already used by ErrorCollectorTest/ShutdownHandlerTest/
    // MessengerFactoryTest/ContainerDetectorTest. Booted BEFORE the
    // CurrentConfig writes below so they land on the real container-shared
    // instance MailService::mail() itself resolves via CurrentConfig::
    // current() -- the pre-boot fallback is a different, memoized object
    // (same "seed after boot, not before" fix shape as every other
    // Current* wrapper, e.g.
    // RequestBootstrapBootConfigOnlyTest.php's own "reuses an
    // already-set CurrentConfigService" test).
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));

    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailSenderName = 'Test Sender';
    // Skips the real theme's text/html mail templates -- header.latte
    // there reads lang_info['code'] directly, which needs a real
    // Lang::load() to populate; the plain-text template mail() always
    // also renders doesn't touch lang_info at all, and is sufficient to
    // prove the real render+send pipeline genuinely ran.
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    // Skips Template::__construct()'s own one-time data_dir_checked
    // write (which otherwise needs a real, activated
    // CurrentConfigService -- more bootstrap than this Unit test
    // should need just to prove delegation).
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    try {
        $spy = new MailServiceTestSpyTransport();
        $mailService = MailServiceTestTransportSwap::with(send_notification_email_handler_test_mail_service(), $spy);
        $handler = new SendNotificationEmailHandler($mailService);

        $handler(new SendNotificationEmailJob(to: 'someone@example.test', args: [
            'subject' => 'Test Subject',
        ]));

        if ($spy->sent === []) {
            throw new RuntimeException('expected mail() to have sent through the spy transport');
        }
        $email = $spy->sent[0];

        $toAddresses = array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo());
        expect($toAddresses)
            ->toBe(['someone@example.test']);
        expect($email->getSubject())
            ->toBe('Test Subject');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});
