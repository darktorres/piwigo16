<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatListNbCatsPageContext;

test('toArray flattens nb_cats', function (): void {
    expect((new CatListNbCatsPageContext(5))->toArray())->toBe(['nb_cats' => 5]);
});
