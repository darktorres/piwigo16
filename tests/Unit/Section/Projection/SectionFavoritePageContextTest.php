<?php

declare(strict_types=1);

use Piwigo\Section\Projection\SectionFavoritePageContext;

test('toArray nests the remove-all URL under the favorite key', function (): void {
    expect((new SectionFavoritePageContext(removeAllUrl: '/index.php?section=favorites&action=remove_all_from_favorites'))->toArray())
        ->toBe([
            'favorite' => [
                'U_FAVORITE' => '/index.php?section=favorites&action=remove_all_from_favorites',
            ],
        ]);
});
