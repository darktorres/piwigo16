<?php

declare(strict_types=1);

use Piwigo\Admin\Install\Request\InstallWizardRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [], new InputValidator());

    expect($request->dbhost)
        ->toBe('localhost')
        ->and($request->dbuser)
        ->toBe('')
        ->and($request->dbpasswd)
        ->toBe('')
        ->and($request->dbname)
        ->toBe('')
        ->and($request->dbdriver)
        ->toBe('mysqli')
        ->and($request->dbport)
        ->toBeNull()
        ->and($request->adminName)
        ->toBe('')
        ->and($request->adminPass1)
        ->toBe('')
        ->and($request->adminPass2)
        ->toBe('')
        ->and($request->adminMail)
        ->toBe('')
        ->and($request->isInstallSubmitted)
        ->toBeFalse()
        ->and($request->isNewsletterSubscribe)
        ->toBeTrue()
        ->and($request->languageParam)
        ->toBeNull()
        ->and($request->isSendCredentialsByMail)
        ->toBeFalse();
});

test('fromArrays falls back to the localhost default when dbhost is explicitly submitted empty', function (): void {
    // Real gap, found via mutation testing: the "defaults for an empty
    // GET/POST" test above only covers a *missing* dbhost key -- an
    // explicitly-submitted empty string is a different code path
    // (is_string() is true, only the `!== ''` check routes it to the
    // 'localhost' default) that a bare "not present at all" case can't
    // distinguish.
    $request = InstallWizardRequest::fromArrays([], [
        'dbhost' => '',
    ], new InputValidator());

    expect($request->dbhost)
        ->toBe('localhost');
});

test('fromArrays parses db credentials from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'dbhost' => 'db.example.test',
        'dbuser' => 'piwigo',
        'dbpasswd' => 'secret',
        'dbname' => 'piwigo_db',
    ], new InputValidator());

    expect($request->dbhost)
        ->toBe('db.example.test')
        ->and($request->dbuser)
        ->toBe('piwigo')
        ->and($request->dbpasswd)
        ->toBe('secret')
        ->and($request->dbname)
        ->toBe('piwigo_db');
});

test('fromArrays parses dbdriver=pgsql from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'dbdriver' => 'pgsql',
    ], new InputValidator());

    expect($request->dbdriver)
        ->toBe('pgsql');
});

test('fromArrays keeps mysqli for an explicit dbdriver=mysqli', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'dbdriver' => 'mysqli',
    ], new InputValidator());

    expect($request->dbdriver)
        ->toBe('mysqli');
});

test('fromArrays rejects a dbdriver value outside mysqli/pgsql', function (): void {
    expect(fn (): InstallWizardRequest => InstallWizardRequest::fromArrays([], [
        'dbdriver' => 'sqlite',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses dbport as an int from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'dbport' => '5432',
    ], new InputValidator());

    expect($request->dbport)
        ->toBe(5432);
});

test('fromArrays leaves dbport null when absent', function (): void {
    $request = InstallWizardRequest::fromArrays([], [], new InputValidator());

    expect($request->dbport)
        ->toBeNull();
});

test('fromArrays rejects a non-numeric dbport', function (): void {
    expect(fn (): InstallWizardRequest => InstallWizardRequest::fromArrays([], [
        'dbport' => 'not-a-port',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays leaves dbport null when submitted as a non-string numeric value', function (): void {
    // Real gap, found via mutation testing: is_string($dbport_raw) is
    // the guard requiring dbport to have arrived as a string (as real
    // $_POST values always do). Passing a genuinely numeric, non-string
    // value distinguishes the real all-AND condition from either
    // BooleanAndToBooleanOr mutant, both of which loosen it with an ||
    // and would wrongly accept this value via is_numeric() alone.
    $request = InstallWizardRequest::fromArrays([], [
        'dbport' => 5432,
    ], new InputValidator());

    expect($request->dbport)
        ->toBeNull();
});

test('fromArrays parses admin credentials from POST', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'admin_name' => 'webmaster',
        'admin_pass1' => 'pw1',
        'admin_pass2' => 'pw1',
        'admin_mail' => 'admin@example.test',
    ], new InputValidator());

    expect($request->adminName)
        ->toBe('webmaster')
        ->and($request->adminPass1)
        ->toBe('pw1')
        ->and($request->adminPass2)
        ->toBe('pw1')
        ->and($request->adminMail)
        ->toBe('admin@example.test');
});

test('fromArrays keeps isNewsletterSubscribe true when install is not submitted, regardless of the checkbox', function (): void {
    $request = InstallWizardRequest::fromArrays([], [], new InputValidator());

    expect($request->isNewsletterSubscribe)
        ->toBeTrue();
});

test('fromArrays reflects the checkbox once install is submitted', function (): void {
    expect(InstallWizardRequest::fromArrays([], [
        'install' => '1',
    ], new InputValidator())->isNewsletterSubscribe)->toBeFalse()
        ->and(InstallWizardRequest::fromArrays([], [
            'install' => '1',
            'newsletter_subscribe' => '1',
        ], new InputValidator())->isNewsletterSubscribe)->toBeTrue();
});

test('fromArrays strips tags from the language param', function (): void {
    $request = InstallWizardRequest::fromArrays([
        'language' => '<script>en_UK</script>',
    ], [], new InputValidator());

    expect($request->languageParam)
        ->toBe('en_UK');
});

test('fromArrays leaves languageParam null when absent', function (): void {
    $request = InstallWizardRequest::fromArrays([], [], new InputValidator());

    expect($request->languageParam)
        ->toBeNull();
});

test('fromArrays reports isSendCredentialsByMail when present', function (): void {
    $request = InstallWizardRequest::fromArrays([], [
        'send_credentials_by_mail' => '1',
    ], new InputValidator());

    expect($request->isSendCredentialsByMail)
        ->toBeTrue();
});

/**
 * [Mutation] A scoped `pest --mutate` rerun leaves 9 mutations
 * "untested" -- zero real gaps, all individually hand-mutation-verified
 * against the real source (temporary sed edit + a full rerun of this
 * file, reverted after):
 *
 * 1. The 7 `'' fallback -> sentinel` EmptyStringToNotEmpty mutations
 *    (dbuser/dbpasswd/dbname/adminName/adminPass1/adminPass2/adminMail,
 *    Lines 61/63/65/72/74/76/78) are NOT actually untested -- each
 *    produces a real, distinct assertion failure against the "returns
 *    defaults for an empty GET/POST" test's own exact `->toBe('')`
 *    assertions when this file is rerun as a whole (confirmed live for
 *    all 7). `pest --mutate`'s own per-mutation test-selection filter
 *    just doesn't correctly attribute that already-covering test to
 *    these specific mutation IDs -- the same tool misattribution on
 *    already-covered code hit repeatedly elsewhere in this campaign.
 * 2. Line 69's EmptyStringToNotEmpty (`$dbport_raw !== ''` inside the
 *    dbport 3-clause `&&` chain) is genuinely inert: whatever this
 *    clause's own truth value becomes, the very next clause
 *    (`is_numeric($dbport_raw)`) is false for every real input that
 *    could ever flip this specific comparison, masking any difference
 *    -- confirmed live.
 * 3. Line 84's BooleanAndToBooleanOr (`isset($get['language']) &&
 *    is_string($get['language'])`) is genuinely inert for the FINAL
 *    $language_param value in every real input scenario: the only
 *    observable difference is an "Undefined array key" PHP warning
 *    (`||`'s own short-circuit rules force evaluating the right operand
 *    even when 'language' is absent, unlike `&&`) -- confirmed live.
 *    `--do-not-fail-on-warning` (mandatory for every `pest --mutate`
 *    baseline in this campaign) specifically prevents that warning from
 *    ever being credited as a failure, matching the same
 *    warning-without-behavior-difference pattern already established
 *    elsewhere (e.g. CookieService.php's non-scalar-value cast).
 */
