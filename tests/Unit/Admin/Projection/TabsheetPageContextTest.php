<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TabsheetPageContext;

test('toArray flattens the sheets map and the selected key, omitting the titlename key when null', function (): void {
    $sheets = [
        'properties' => [
            'caption' => 'Properties',
            'url' => '/admin.php?page=album',
        ],
    ];

    expect(new TabsheetPageContext(sheets: $sheets, selected: 'properties', titlenameKey: null, titlenameValue: null)->toArray())
        ->toBe([
            'tabsheet' => $sheets,
            'tabsheet_selected' => 'properties',
        ]);
});

test('toArray assigns the bracketed selected caption under the dynamic titlename key when set', function (): void {
    $sheets = [
        'properties' => [
            'caption' => 'Properties',
            'url' => '/admin.php?page=album',
        ],
    ];

    $result = new TabsheetPageContext(sheets: $sheets, selected: 'properties', titlenameKey: 'MY_TITLE', titlenameValue: '[Properties]')
        ->toArray();

    expect($result['MY_TITLE'])->toBe('[Properties]');
});
