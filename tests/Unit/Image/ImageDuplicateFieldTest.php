<?php

declare(strict_types=1);

use Piwigo\Image\ImageDuplicateField;

/**
 * Piwigo\Image\ImageDuplicateField -- ImageRepository::
 * findIdsGroupedByDuplicateFields()'s $fields list, enumerated. No
 * dedicated Integration/Browser spec of its own.
 */
test('column maps each case to its real images table column name', function (): void {
    expect(ImageDuplicateField::File->column())->toBe('file')
        ->and(ImageDuplicateField::Md5sum->column())->toBe('md5sum')
        ->and(ImageDuplicateField::DateCreation->column())->toBe('date_creation')
        ->and(ImageDuplicateField::Width->column())->toBe('width')
        ->and(ImageDuplicateField::Height->column())->toBe('height');
});
