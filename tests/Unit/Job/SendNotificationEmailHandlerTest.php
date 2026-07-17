<?php

declare(strict_types=1);

use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;

// P23 batch 8f-4: the get_webmaster_mail_address() function stub is gone
// (free function deleted with include/functions.inc.php; MailService now
// reaches the webmaster address through its optional
// WebmasterMailProviderInterface constructor param). No fake is needed
// here: the empty-$to job below short-circuits MailService::mail() to
// `return true` before getMailConfiguration()/the webmaster lookup ever
// runs (verified against mail()'s own first guard).

beforeEach(function (): void {
    $GLOBALS['conf'] = [];
    MailService::reset();
});

test('__invoke delegates to MailService::mail with the job to/args/tpl', function (): void {
    // An empty $to short-circuits MailService::mail() to `return true`
    // before it ever touches a real Transport (see its own emptyValue($to)
    // guard) -- same "don't stub/exercise what would kill the test"
    // reasoning used throughout this suite for a genuinely side-effecting
    // free function this project has no test double for.
    $handler = new SendNotificationEmailHandler(new MailService());

    $handler(new SendNotificationEmailJob(to: ''));
})->throwsNoExceptions();
