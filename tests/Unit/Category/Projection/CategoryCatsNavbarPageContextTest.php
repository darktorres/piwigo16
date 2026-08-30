<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryCatsNavbarPageContext;
use Piwigo\Core\Projection\Navbar;

test('toArray passes the navbar VO through unflattened', function (): void {
    $navbar = new Navbar(currentPage: 1, nbPage: 3);

    // index.latte reads $cats_navbar's properties as of P58-A; the
    // identity assertion is what says the flatten is gone, where an
    // equality one would still pass against a round-tripping array.
    expect(new CategoryCatsNavbarPageContext($navbar)->toArray())
        ->toBe([
            'cats_navbar' => $navbar,
        ]);
});
