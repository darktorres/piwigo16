<?php

declare(strict_types=1);

use Piwigo\Session\Session;

test('fromSuperglobal builds an instance regardless of raw content', function (): void {
    // fromSuperglobal()'s own return type already guarantees the instance's
    // class; the actual forward-looking guard here (see the class's own
    // "no typed slots yet" docblock) is that unusual raw content doesn't
    // break construction once real parsing is added.
    expect(static fn (): Session => Session::fromSuperglobal([
        'anything' => 'goes',
        'pwg_uid' => 5,
    ]))->not->toThrow(Throwable::class);
});

test('fromSuperglobal accepts an empty array', function (): void {
    expect(static fn (): Session => Session::fromSuperglobal([]))->not->toThrow(Throwable::class);
});

test('persistInto leaves the target array untouched -- no typed slots yet', function (): void {
    $session = Session::fromSuperglobal([]);
    $target = [
        'plugin_scratch' => 'unrelated',
        'pwg_uid' => 5,
    ];

    $session->persistInto($target);

    expect($target)
        ->toBe([
            'plugin_scratch' => 'unrelated',
            'pwg_uid' => 5,
        ]);
});
