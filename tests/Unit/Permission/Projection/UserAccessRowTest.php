<?php

declare(strict_types=1);

use Piwigo\Permission\Projection\UserAccessRow;

test('constructs with the given user id and category id', function (): void {
    $row = new UserAccessRow(5, 3);

    expect($row->userId)
        ->toBe(5)
        ->and($row->catId)
        ->toBe(3);
});
