<?php

declare(strict_types=1);

use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;

// getMailSenderEmail()/getMailConfiguration() call the real
// get_webmaster_mail_address() -- same minimal stub as
// tests/Unit/Mail/MailServiceTest.php.
if (! function_exists('get_webmaster_mail_address')) {
    function get_webmaster_mail_address(): string
    {
        return 'webmaster@example.test';
    }
}

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
