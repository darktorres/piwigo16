<?php

declare(strict_types=1);

use Piwigo\Controller\Request\ProfileFormSubmitRequest;

test('fromArray returns defaults for an empty POST', function (): void {
    $request = ProfileFormSubmitRequest::fromArray([]);

    expect($request->isValidateSubmitted)
        ->toBeFalse()
        ->and($request->isSubmitPresent)
        ->toBeFalse()
        ->and($request->post)
        ->toBe([]);
});

test('fromArray reports isValidateSubmitted when validate is present', function (): void {
    $request = ProfileFormSubmitRequest::fromArray([
        'validate' => '1',
    ]);

    expect($request->isValidateSubmitted)
        ->toBeTrue();
});

test('fromArray reports isSubmitPresent independently of validate', function (): void {
    $request = ProfileFormSubmitRequest::fromArray([
        'submit' => '1',
    ]);

    expect($request->isSubmitPresent)
        ->toBeTrue()
        ->and($request->isValidateSubmitted)
        ->toBeFalse();
});

test('fromArray retains the full raw post bag', function (): void {
    $request = ProfileFormSubmitRequest::fromArray([
        'username' => 'alice',
        'theme' => 'clear',
    ]);

    expect($request->post)
        ->toBe([
            'username' => 'alice',
            'theme' => 'clear',
        ]);
});
