<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryCatsNavbarPageContext;
use Piwigo\Core\Projection\Navbar;

test('toArray flattens the navbar VO', function (): void {
    $navbar = new Navbar(currentPage: 1.0, nbPage: 3);

    expect(new CategoryCatsNavbarPageContext($navbar)->toArray())
        ->toBe([
            'cats_navbar' => [
                'CURRENT_PAGE' => 1.0,
                'NB_PAGE' => 3,
            ],
        ]);
});
