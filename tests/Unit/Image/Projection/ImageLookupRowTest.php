<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\Projection\ImageLookupRow;

test('constructs with distinct values for every property', function (): void {
    $row = new ImageLookupRow(ImageId::from(4), 'fixture-photo-1.jpg', 2);

    expect($row->id->value)->toBe(4)
        ->and($row->file)->toBe('fixture-photo-1.jpg')
        ->and($row->level)->toBe(2);
});
