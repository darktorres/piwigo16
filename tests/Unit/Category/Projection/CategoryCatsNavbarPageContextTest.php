<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryCatsNavbarPageContext;

test('toArray flattens the navbar array', function (): void {
    $navbar = [
        'CURRENT_PAGE' => 1.0,
        'NB_PAGE' => 3,
    ];

    expect(new CategoryCatsNavbarPageContext($navbar)->toArray())
        ->toBe([
            'cats_navbar' => $navbar,
        ]);
});
