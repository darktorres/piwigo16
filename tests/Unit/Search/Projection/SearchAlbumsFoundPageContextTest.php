<?php

declare(strict_types=1);

use Piwigo\Search\Projection\SearchAlbumsFoundPageContext;

test('toArray flattens under ALBUMS_FOUND', function (): void {
    $context = new SearchAlbumsFoundPageContext(['Holidays', 'Family &gt; Birthdays']);

    expect($context->toArray())->toBe([
        'ALBUMS_FOUND' => ['Holidays', 'Family &gt; Birthdays'],
    ]);
});
