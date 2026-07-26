<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\NotificationByMailRequest;

test('fromArrays defaults pageMode to send for an empty GET', function (): void {
    $request = NotificationByMailRequest::fromArrays([], []);

    expect($request->pageMode)->toBe('send')
        ->and($request->post)->toBe([]);
});

test('fromArrays reads a valid mode', function (): void {
    $request = NotificationByMailRequest::fromArrays(['mode' => 'param'], []);

    expect($request->pageMode)->toBe('param');
});

test('fromArrays rejects an invalid mode', function (): void {
    expect(fn (): NotificationByMailRequest => NotificationByMailRequest::fromArrays(['mode' => 'delete'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays retains the full raw post bag', function (): void {
    $request = NotificationByMailRequest::fromArrays([], ['nbm_send_mail_as' => 'html', 'param_submit' => '1']);

    expect($request->post)->toBe(['nbm_send_mail_as' => 'html', 'param_submit' => '1']);
});
