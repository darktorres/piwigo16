<?php

declare(strict_types=1);

use Piwigo\Permission\Projection\GroupAccessRow;

test('constructs with the given group id and category id', function (): void {
    $row = new GroupAccessRow(2, 3);

    expect($row->groupId)
        ->toBe(2)
        ->and($row->catId)
        ->toBe(3);
});
