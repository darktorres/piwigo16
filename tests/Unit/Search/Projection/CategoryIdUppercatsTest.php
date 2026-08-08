<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Search\Projection\CategoryIdUppercats;

test('constructs with the given id and uppercats', function (): void {
    $row = new CategoryIdUppercats(CategoryId::from(2), '1,2');

    expect($row->id)->toEqual(CategoryId::from(2))
        ->and($row->uppercats)->toBe('1,2');
});
