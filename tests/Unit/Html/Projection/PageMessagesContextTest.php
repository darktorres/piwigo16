<?php

declare(strict_types=1);

use Piwigo\Html\Projection\PageMessagesContext;

test('toArray omits every key when all fields are null', function (): void {
    expect(new PageMessagesContext(errors: null, infos: null, warnings: null, messages: null)->toArray())
        ->toBe([]);
});

test('toArray includes only the errors key when set', function (): void {
    $result = new PageMessagesContext(errors: [
        'login_page_error' => 'Bad password',
    ], infos: null, warnings: null, messages: null)->toArray();

    expect($result)
        ->toBe([
            'errors' => [
                'login_page_error' => 'Bad password',
            ],
        ]);
});

test('toArray includes every key when all 4 are set', function (): void {
    $result = new PageMessagesContext(
        errors: ['Something went wrong'],
        infos: ['Saved successfully'],
        warnings: ['Deprecated option used'],
        messages: ['Welcome back'],
    )->toArray();

    expect($result)
        ->toBe([
            'errors' => ['Something went wrong'],
            'infos' => ['Saved successfully'],
            'warnings' => ['Deprecated option used'],
            'messages' => ['Welcome back'],
        ]);
});
