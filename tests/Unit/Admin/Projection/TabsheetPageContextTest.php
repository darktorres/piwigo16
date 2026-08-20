<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TabsheetPageContext;

test('toArray is empty when the titlename key is null', function (): void {
    expect(new TabsheetPageContext(titlenameKey: null, titlenameValue: null)->toArray())
        ->toBe([]);
});

test('toArray assigns the bracketed selected caption under the dynamic titlename key when set', function (): void {
    $result = new TabsheetPageContext(titlenameKey: 'MY_TITLE', titlenameValue: '[Properties]')
        ->toArray();

    expect($result)
        ->toBe([
            'MY_TITLE' => '[Properties]',
        ]);
});
