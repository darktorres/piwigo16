<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Mail\MailService;

// P23 batch 8f-4: the get_webmaster_mail_address() function stub is gone
// (free function deleted with include/functions.inc.php). MailService now
// takes WebmasterMailProviderInterface as an optional constructor param
// (lazily defaulting to the real Piwigo\Users\UserRepository, which would
// need a DB connection this isolated test doesn't have), so the tests
// whose paths reach the webmaster lookup (getMailConfiguration() always
// calls getMailSenderEmail()) construct the service with this real fake.
function mail_service_with_fake_webmaster(): MailService
{
    return new MailService(new class implements WebmasterMailProviderInterface {
        #[\Override]
        public function getWebmasterMailAddress(): string
        {
            return 'webmaster@example.test';
        }
    });
}

beforeEach(function (): void {
    MailService::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('formatEmail wraps a name and email into "name <email>"', function (): void {
    $service = new MailService();

    expect($service->formatEmail('Jane Doe', 'jane@example.test'))->toBe('"Jane Doe" <jane@example.test>');
});

test('formatEmail returns a bare "<email>" when name is empty', function (): void {
    $service = new MailService();

    expect($service->formatEmail('', 'jane@example.test'))->toBe('<jane@example.test>');
});

test('formatEmail strips newlines from both name and email (header injection)', function (): void {
    $service = new MailService();

    expect($service->formatEmail("Jane\r\nBcc: evil@test", 'jane@example.test'))
        ->toBe('"JaneBcc: evil@test" <jane@example.test>');
});

test('formatEmail returns the name concatenated as-is when the email already contains angle brackets', function (): void {
    $service = new MailService();

    expect($service->formatEmail('Jane Doe', 'Real Name <jane@example.test>'))
        ->toBe('"Jane Doe" Real Name <jane@example.test>');
});

test('unformatEmail parses a "name <email>" string', function (): void {
    $service = new MailService();

    expect($service->unformatEmail('Jane Doe <jane@example.test>'))->toBe([
        'email' => 'jane@example.test',
        'name' => 'Jane Doe',
    ]);
});

test('unformatEmail treats a bare email string as email with no name', function (): void {
    $service = new MailService();

    expect($service->unformatEmail('jane@example.test'))->toBe([
        'email' => 'jane@example.test',
        'name' => '',
    ]);
});

test('unformatEmail accepts an array input with email and name keys', function (): void {
    $service = new MailService();

    expect($service->unformatEmail(['email' => 'jane@example.test', 'name' => 'Jane']))->toBe([
        'email' => 'jane@example.test',
        'name' => 'Jane',
    ]);
});

test('unformatEmail throws on an array input missing the email key', function (): void {
    $service = new MailService();

    expect(fn (): array => $service->unformatEmail(['name' => 'Jane']))->toThrow(InvalidArgumentException::class);
});

test('getCleanRecipientsList returns an empty list for empty input', function (): void {
    $service = new MailService();

    expect($service->getCleanRecipientsList(null))->toBe([])
        ->and($service->getCleanRecipientsList(''))->toBe([])
        ->and($service->getCleanRecipientsList([]))->toBe([]);
});

test('getCleanRecipientsList parses a comma-separated string', function (): void {
    $service = new MailService();

    expect($service->getCleanRecipientsList('a@test.com,Bob <b@test.com>'))->toBe([
        ['email' => 'a@test.com', 'name' => ''],
        ['email' => 'b@test.com', 'name' => 'Bob'],
    ]);
});

test('getCleanRecipientsList deduplicates by email', function (): void {
    $service = new MailService();

    expect($service->getCleanRecipientsList('a@test.com,a@test.com'))->toBe([
        ['email' => 'a@test.com', 'name' => ''],
    ]);
});

test('getCleanRecipientsList accepts a plain array of emails', function (): void {
    $service = new MailService();

    expect($service->getCleanRecipientsList(['a@test.com', 'b@test.com']))->toBe([
        ['email' => 'a@test.com', 'name' => ''],
        ['email' => 'b@test.com', 'name' => ''],
    ]);
});

test('getCleanRecipientsList accepts a single hashmap recipient', function (): void {
    $service = new MailService();

    expect($service->getCleanRecipientsList(['email' => 'a@test.com', 'name' => 'A']))->toBe([
        ['email' => 'a@test.com', 'name' => 'A'],
    ]);
});

test('getCleanRecipientsList falls back to a scalar-cast email for a non-array, non-string item inside an array of hashmaps', function (): void {
    $service = new MailService();

    // The first item being an array routes into the "array of hashmaps"
    // branch; the second item (a bare int) is neither an array nor a
    // string, so it takes the scalar-cast fallback instead of
    // unformatEmail().
    expect($service->getCleanRecipientsList([['email' => 'a@test.com'], 42]))->toBe([
        ['email' => 'a@test.com', 'name' => ''],
        ['email' => '42', 'name' => ''],
    ]);
});

test('getStrictEmailList strips names, keeping only the bare email addresses', function (): void {
    $service = new MailService();

    expect($service->getStrictEmailList('Jane <jane@test.com>, bob@test.com'))->toBe('jane@test.com,bob@test.com');
});

test('getStrEmailFormat maps the html flag to a MIME content type', function (): void {
    $service = new MailService();

    expect($service->getStrEmailFormat(true))->toBe('text/html')
        ->and($service->getStrEmailFormat(false))->toBe('text/plain');
});

test('moveCssToBody returns an empty string unchanged', function (): void {
    $service = new MailService();

    expect($service->moveCssToBody(''))->toBe('');
});

test('moveCssToBody inlines a <style> block into the element it targets', function (): void {
    $service = new MailService();

    $html = '<html><head><style>p { color: red; }</style></head><body><p>hi</p></body></html>';
    $result = $service->moveCssToBody($html);

    expect($result)->toContain('style="color: red;"');
});

test('getMailSenderName falls back to gallery_title when mail_sender_name is unset', function (): void {
    CurrentConfig::setGalleryTitle('My Gallery');
    $service = new MailService();

    expect($service->getMailSenderName())->toBe('My Gallery');
});

test('getMailSenderName uses mail_sender_name when configured', function (): void {
    CurrentConfig::setMailSenderName('Custom Sender');
    $service = new MailService();

    expect($service->getMailSenderName())->toBe('Custom Sender');
});

test('getMailSenderEmail uses the configured mail_sender_email without falling back to the webmaster address', function (): void {
    CurrentConfig::setMailSenderEmail('sender@example.test');
    // No WebmasterMailProviderInterface fake needed here -- a configured,
    // non-empty mail_sender_email short-circuits before webmasterMailAddress()
    // (which would otherwise need a real DB connection) is ever reached.
    $service = new MailService();

    expect($service->getMailSenderEmail())->toBe('sender@example.test');
});

test('getMailConfiguration reports use_smtp false when smtp_host is unset', function (): void {
    $service = mail_service_with_fake_webmaster();

    expect($service->getMailConfiguration()['use_smtp'])->toBeFalse();
});

test('getMailConfiguration reports use_smtp true when smtp_host is configured', function (): void {
    CurrentConfig::setSmtpHost('smtp.example.test');
    $service = mail_service_with_fake_webmaster();

    expect($service->getMailConfiguration()['use_smtp'])->toBeTrue();
});

test('getMailConfiguration reads debug_mail-adjacent smtp settings from CurrentConfig::', function (): void {
    CurrentConfig::setSmtpHost('smtp.example.test');
    CurrentConfig::setSmtpUser('mailuser');
    CurrentConfig::setSmtpPassword('secret');
    CurrentConfig::setSmtpSecure('tls');
    $service = mail_service_with_fake_webmaster();

    $config = $service->getMailConfiguration();

    expect($config['smtp_user'])->toBe('mailuser')
        ->and($config['smtp_password'])->toBe('secret')
        ->and($config['smtp_secure'])->toBe('tls');
});

test('generateResetPasswordMail builds an HTML mail with the reset link and gallery-title-prefixed subject', function (): void {
    $service = new MailService();

    $mail = $service->generateResetPasswordMail('jane', 'https://example.test/password.php?key=abc', 'My Gallery', '2 hours');

    expect($mail['subject'])->toBe('[My Gallery] Password Reset');
    expect($mail['content_format'])->toBe('text/html');
    expect($mail['content'])->toContain('jane');
    expect($mail['content'])->toContain('https://example.test/password.php?key=abc');
    expect($mail['content'])->toContain('2 hours');
});

test('generateSetPasswordMail builds an HTML mail with the activation link and a welcome subject', function (): void {
    $service = new MailService();

    $mail = $service->generateSetPasswordMail('jane', 'https://example.test/password.php?key=xyz', 'My Gallery', '48 hours');

    expect($mail['subject'])->toBe('Welcome to My Gallery');
    expect($mail['content_format'])->toBe('text/html');
    expect($mail['content'])->toContain('jane');
    expect($mail['content'])->toContain('https://example.test/password.php?key=xyz');
    expect($mail['content'])->toContain('48 hours');
});

test('generateCodeVerificationMail embeds the raw verification code and the current gallery title', function (): void {
    CurrentConfig::setGalleryTitle('My Gallery');
    $service = new MailService();

    $mail = $service->generateCodeVerificationMail('482913');

    expect($mail['subject'])->toBe('[My Gallery] Your verification code');
    expect($mail['content_format'])->toBe('text/html');
    expect($mail['content'])->toContain('482913');
});

test('generateSuccessResetPasswordMail omits the API-key-revocation notice when there are no API keys', function (): void {
    $service = new MailService();

    $mail = $service->generateSuccessResetPasswordMail('jane', 0);

    expect($mail['content'])->toContain('Hello jane,');
    expect($mail['content'])->not->toContain('API keys');
});

test('generateSuccessResetPasswordMail includes the API-key-revocation notice with the real key count when there are some', function (): void {
    $service = new MailService();

    $mail = $service->generateSuccessResetPasswordMail('jane', 3);

    expect($mail['content'])->toContain('Hello jane,');
    expect($mail['content'])->toContain('3 API keys');
});
