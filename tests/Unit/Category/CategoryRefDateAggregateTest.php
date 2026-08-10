<?php

declare(strict_types=1);

use Piwigo\Category\CategoryRefDateAggregate;

/**
 * Piwigo\Category\CategoryRefDateAggregate -- CategoryRepository::
 * findRefDatesByCategoryIds()'s $minmax parameter, enumerated. No
 * dedicated Integration/Browser spec of its own.
 */
test('sqlFunction maps each case to its real SQL aggregate function name', function (): void {
    expect(CategoryRefDateAggregate::Min->sqlFunction())->toBe('MIN')
        ->and(CategoryRefDateAggregate::Max->sqlFunction())->toBe('MAX');
});
