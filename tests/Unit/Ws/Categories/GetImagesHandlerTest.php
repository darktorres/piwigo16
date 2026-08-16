<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Categories\GetImagesHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\GetImagesHandler -- `pwg.categories.getImages`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers its "cat_id not found" 404 guard via a real DB read against a
 * deliberately non-existent `cat_id`, same B2-pattern approach as this
 * campaign's Repository/Service tests.
 */
function pwgCategoriesGetImagesHandlerTestSubject(): GetImagesHandler
{
    $handler = Kernel::container()->get(GetImagesHandler::class);
    if (! $handler instanceof GetImagesHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetImagesHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 404 WsErrorResponse for a cat_id with no real match', function (): void {
    $handler = pwgCategoriesGetImagesHandlerTestSubject();

    $result = $handler([
        'cat_id' => [999999],
        'recursive' => false,
        'per_page' => 10,
        'page' => 0,
        'order' => null,
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(404)
            ->and($result->message())
            ->toBe('cat_id {999999} not found');
    }
});
