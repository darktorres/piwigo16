<?php

declare(strict_types=1);

use Piwigo\Controller\Request\RegisterSubmitRequest;

test('fromArray reports not submitted and empty defaults for an empty POST', function (): void {
    $request = RegisterSubmitRequest::fromArray([]);

    expect($request->isSubmitted)
        ->toBeFalse()
        ->and($request->key)
        ->toBe('')
        ->and($request->password)
        ->toBe('')
        ->and($request->passwordConf)
        ->toBe('')
        ->and($request->login)
        ->toBe('')
        ->and($request->mailAddress)
        ->toBeNull()
        ->and($request->sendPasswordByMail)
        ->toBeFalse();
});

test('fromArray parses a full registration submission', function (): void {
    $request = RegisterSubmitRequest::fromArray([
        'submit' => '1',
        'key' => '1:2:3',
        'password' => 'secret',
        'password_conf' => 'secret',
        'login' => 'alice',
        'mail_address' => 'alice@example.test',
        'send_password_by_mail' => '1',
    ]);

    expect($request->isSubmitted)
        ->toBeTrue()
        ->and($request->key)
        ->toBe('1:2:3')
        ->and($request->password)
        ->toBe('secret')
        ->and($request->passwordConf)
        ->toBe('secret')
        ->and($request->login)
        ->toBe('alice')
        ->and($request->mailAddress)
        ->toBe('alice@example.test')
        ->and($request->sendPasswordByMail)
        ->toBeTrue();
});

test('fromArray normalizes a non-string password/password_conf/login to an empty string', function (): void {
    $request = RegisterSubmitRequest::fromArray([
        'password' => ['x'],
        'password_conf' => ['y'],
        'login' => ['z'],
    ]);

    expect($request->password)
        ->toBe('')
        ->and($request->passwordConf)
        ->toBe('')
        ->and($request->login)
        ->toBe('');
});

test('fromArray normalizes a non-string mail_address to null', function (): void {
    $request = RegisterSubmitRequest::fromArray([
        'mail_address' => ['x'],
    ]);

    expect($request->mailAddress)
        ->toBeNull();
});
