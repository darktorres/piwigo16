<?php

declare(strict_types=1);

use Piwigo\Category\CategoryRefDateField;

/**
 * Piwigo\Category\CategoryRefDateField -- CategoryRepository::
 * findRefDatesByCategoryIds()'s $field parameter, enumerated. No
 * dedicated Integration/Browser spec of its own.
 */
test('dqlProperty maps each case to its real DQL property path against the i alias', function (): void {
    expect(CategoryRefDateField::DateCreation->dqlProperty())->toBe('i.dateCreation')
        ->and(CategoryRefDateField::DateAvailable->dqlProperty())->toBe('i.dateAvailable');
});
