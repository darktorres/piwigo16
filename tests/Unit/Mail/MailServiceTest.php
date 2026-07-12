<?php

declare(strict_types=1);

use Piwigo\Mail\MailService;

// getMailSenderEmail()/getMailConfiguration() call the real
// get_webmaster_mail_address() (unqualified, resolves to the global
// namespace) -- a stable, already-migrated function that needs full app
// bootstrap this isolated test doesn't load. Same "minimal stub to load
// standalone" pattern as tests/Unit/PasswordHashTest.php.
if (! function_exists('get_webmaster_mail_address')) {
    function get_webmaster_mail_address(): string
    {
        return 'webmaster@example.test';
    }
}

beforeEach(function (): void {
    // MailService reads mail settings from the real, request-live $conf
    // global (see the class's own docblock: Piwigo\Config\Config's typed
    // accessors are NOT synced with DB-persisted config -- ConfigService::
    // loadConfFromDb() is never called from the live bootstrap sequence),
    // so tests configure $GLOBALS['conf'] directly rather than Config::override().
    $GLOBALS['conf'] = [];
    MailService::reset();
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

    expect(fn () => $service->unformatEmail(['name' => 'Jane']))->toThrow(InvalidArgumentException::class);
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
    $GLOBALS['conf'] = ['gallery_title' => 'My Gallery'];
    $service = new MailService();

    expect($service->getMailSenderName())->toBe('My Gallery');
});

test('getMailSenderName uses mail_sender_name when configured', function (): void {
    $GLOBALS['conf'] = ['mail_sender_name' => 'Custom Sender'];
    $service = new MailService();

    expect($service->getMailSenderName())->toBe('Custom Sender');
});

test('getMailConfiguration reports use_smtp false when smtp_host is unset', function (): void {
    $service = new MailService();

    expect($service->getMailConfiguration()['use_smtp'])->toBeFalse();
});

test('getMailConfiguration reports use_smtp true when smtp_host is configured', function (): void {
    $GLOBALS['conf'] = ['smtp_host' => 'smtp.example.test'];
    $service = new MailService();

    expect($service->getMailConfiguration()['use_smtp'])->toBeTrue();
});

test('getMailConfiguration reads debug_mail-adjacent smtp settings from the live $conf global, not Config::', function (): void {
    $GLOBALS['conf'] = [
        'smtp_host' => 'smtp.example.test',
        'smtp_user' => 'mailuser',
        'smtp_password' => 'secret',
        'smtp_secure' => 'tls',
    ];
    $service = new MailService();

    $config = $service->getMailConfiguration();

    expect($config['smtp_user'])->toBe('mailuser')
        ->and($config['smtp_password'])->toBe('secret')
        ->and($config['smtp_secure'])->toBe('tls');
});
