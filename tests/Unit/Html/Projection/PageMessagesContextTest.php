<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Html\Projection\PageMessagesContext;

test('toArray omits every key when all fields are null', function (): void {
    expect(new PageMessagesContext(errors: null, infos: null, warnings: null, messages: null)->toArray())
        ->toBe([]);
});

test('toArray includes only the errors key when set', function (): void {
    $result = new PageMessagesContext(errors: [
        'login_page_error' => new Html('Bad password'),
    ], infos: null, warnings: null, messages: null)->toArray();

    expect($result)
        ->toEqual([
            'errors' => [
                'login_page_error' => new Html('Bad password'),
            ],
        ]);
});

test('toArray includes every key when all 4 are set', function (): void {
    $result = new PageMessagesContext(
        errors: [new Html('Something went wrong')],
        infos: [new Html('Saved successfully')],
        warnings: [new Html('Deprecated option used')],
        messages: [new Html('Welcome back')],
    )->toArray();

    expect($result)
        ->toEqual([
            'errors' => [new Html('Something went wrong')],
            'infos' => [new Html('Saved successfully')],
            'warnings' => [new Html('Deprecated option used')],
            'messages' => [new Html('Welcome back')],
        ]);
});
