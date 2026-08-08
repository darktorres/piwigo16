<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ElementSetRanksSaveSuccessPageContext;

test('toArray flattens the save success message', function (): void {
    expect((new ElementSetRanksSaveSuccessPageContext('Album updated successfully'))->toArray())
        ->toBe(['save_success' => 'Album updated successfully']);
});
