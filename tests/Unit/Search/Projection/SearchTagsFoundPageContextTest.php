<?php

declare(strict_types=1);

use Piwigo\Search\Projection\SearchTagsFoundPageContext;

test('toArray flattens under TAGS_FOUND', function (): void {
    $context = new SearchTagsFoundPageContext(['<a href="/index?tags=1">sunset</a>']);

    expect($context->toArray())
        ->toBe([
            'TAGS_FOUND' => ['<a href="/index?tags=1">sunset</a>'],
        ]);
});
