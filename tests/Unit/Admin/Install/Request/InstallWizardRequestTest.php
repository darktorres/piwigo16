<?php

declare(strict_types=1);

use Piwigo\Admin\Install\Request\InstallWizardRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], []);

    expect($request->dl)->toBeNull()
        ->and($request->dbhost)->toBe('localhost')
        ->and($request->dbuser)->toBe('')
        ->and($request->dbpasswd)->toBe('')
        ->and($request->dbname)->toBe('')
        ->and($request->dbdriver)->toBe('mysqli')
        ->and($request->dbport)->toBeNull()
        ->and($request->adminName)->toBe('')
        ->and($request->adminPass1)->toBe('')
        ->and($request->adminPass2)->toBe('')
        ->and($request->adminMail)->toBe('')
        ->and($request->isInstallSubmitted)->toBeFalse()
        ->and($request->isNewsletterSubscribe)->toBeTrue()
        ->and($request->languageParam)->toBeNull()
        ->and($request->isSendCredentialsByMail)->toBeFalse();
});

test('fromArrays parses dl as a 32-char hex string', function (): void {
    $request = InstallWizardRequest::fromArrays(['dl' => str_repeat('a', 32)], []);

    expect($request->dl)->toBe(str_repeat('a', 32));
});

test('fromArrays rejects a malformed dl', function (): void {
    expect(fn (): InstallWizardRequest => InstallWizardRequest::fromArrays(['dl' => 'not-hex'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays falls back to the localhost default when dbhost is explicitly submitted empty', function (): void {
    // Real gap, found via mutation testing: the "defaults for an empty
    // GET/POST" test above only covers a *missing* dbhost key -- an
    // explicitly-submitted empty string is a different code path
    // (is_string() is true, only the `!== ''` check routes it to the
    // 'localhost' default) that a bare "not present at all" case can't
    // distinguish.
    $request = InstallWizardRequest::fromArrays([], ['dbhost' => '']);

    expect($request->dbhost)->toBe('localhost');
});

test('fromArrays treats an explicitly empty dl the same as a missing one', function (): void {
    $request = InstallWizardRequest::fromArrays(['dl' => ''], []);

    expect($request->dl)->toBeNull();
});

test('fromArrays parses db credentials from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'dbhost' => 'db.example.test',
        'dbuser' => 'piwigo',
        'dbpasswd' => 'secret',
        'dbname' => 'piwigo_db',
    ]);

    expect($request->dbhost)->toBe('db.example.test')
        ->and($request->dbuser)->toBe('piwigo')
        ->and($request->dbpasswd)->toBe('secret')
        ->and($request->dbname)->toBe('piwigo_db');
});

test('fromArrays parses dbdriver=pgsql from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], ['dbdriver' => 'pgsql']);

    expect($request->dbdriver)->toBe('pgsql');
});

test('fromArrays keeps mysqli for an explicit dbdriver=mysqli', function (): void {
    $request = InstallWizardRequest::fromArrays([], ['dbdriver' => 'mysqli']);

    expect($request->dbdriver)->toBe('mysqli');
});

test('fromArrays rejects a dbdriver value outside mysqli/pgsql', function (): void {
    expect(fn (): InstallWizardRequest => InstallWizardRequest::fromArrays([], ['dbdriver' => 'sqlite']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses dbport as an int from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], ['dbport' => '5432']);

    expect($request->dbport)->toBe(5432);
});

test('fromArrays leaves dbport null when absent', function (): void {
    $request = InstallWizardRequest::fromArrays([], []);

    expect($request->dbport)->toBeNull();
});

test('fromArrays rejects a non-numeric dbport', function (): void {
    expect(fn (): InstallWizardRequest => InstallWizardRequest::fromArrays([], ['dbport' => 'not-a-port']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses admin credentials from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'admin_name' => 'webmaster',
        'admin_pass1' => 'pw1',
        'admin_pass2' => 'pw1',
        'admin_mail' => 'admin@example.test',
    ]);

    expect($request->adminName)->toBe('webmaster')
        ->and($request->adminPass1)->toBe('pw1')
        ->and($request->adminPass2)->toBe('pw1')
        ->and($request->adminMail)->toBe('admin@example.test');
});

test('fromArrays keeps isNewsletterSubscribe true when install is not submitted, regardless of the checkbox', function (): void {
    $request = InstallWizardRequest::fromArrays([], []);

    expect($request->isNewsletterSubscribe)->toBeTrue();
});

test('fromArrays reflects the checkbox once install is submitted', function (): void {
    expect(InstallWizardRequest::fromArrays([], ['install' => '1'])->isNewsletterSubscribe)->toBeFalse()
        ->and(InstallWizardRequest::fromArrays([], ['install' => '1', 'newsletter_subscribe' => '1'])->isNewsletterSubscribe)->toBeTrue();
});

test('fromArrays strips tags from the language param', function (): void {
    $request = InstallWizardRequest::fromArrays(['language' => '<script>en_UK</script>'], []);

    expect($request->languageParam)->toBe('en_UK');
});

test('fromArrays leaves languageParam null when absent', function (): void {
    $request = InstallWizardRequest::fromArrays([], []);

    expect($request->languageParam)->toBeNull();
});

test('fromArrays reports isSendCredentialsByMail when present', function (): void {
    $request = InstallWizardRequest::fromArrays([], ['send_credentials_by_mail' => '1']);

    expect($request->isSendCredentialsByMail)->toBeTrue();
});
