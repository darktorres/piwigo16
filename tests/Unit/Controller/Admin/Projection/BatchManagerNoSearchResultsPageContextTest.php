<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\BatchManagerNoSearchResultsPageContext;

test('toArray flattens the unmatched terms list', function (): void {
    expect(new BatchManagerNoSearchResultsPageContext(['foo', 'bar'])->toArray())
        ->toBe([
            'no_search_results' => ['foo', 'bar'],
        ]);
});
