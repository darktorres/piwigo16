<?php

declare(strict_types=1);

use Piwigo\Session\Session;

test('fromSuperglobal builds an instance regardless of raw content', function (): void {
    $session = Session::fromSuperglobal(['anything' => 'goes', 'pwg_uid' => 5]);

    expect($session)->toBeInstanceOf(Session::class);
});

test('fromSuperglobal accepts an empty array', function (): void {
    $session = Session::fromSuperglobal([]);

    expect($session)->toBeInstanceOf(Session::class);
});

test('persistInto leaves the target array untouched -- no typed slots yet', function (): void {
    $session = Session::fromSuperglobal([]);
    $target = ['plugin_scratch' => 'unrelated', 'pwg_uid' => 5];

    $session->persistInto($target);

    expect($target)->toBe(['plugin_scratch' => 'unrelated', 'pwg_uid' => 5]);
});
