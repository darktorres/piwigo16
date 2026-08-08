<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TabsheetPageContext;

test('toArray flattens the sheets map and the selected key', function (): void {
    $sheets = ['properties' => ['caption' => 'Properties', 'url' => '/admin.php?page=album']];

    expect((new TabsheetPageContext(sheets: $sheets, selected: 'properties'))->toArray())->toBe([
        'tabsheet' => $sheets,
        'tabsheet_selected' => 'properties',
    ]);
});
