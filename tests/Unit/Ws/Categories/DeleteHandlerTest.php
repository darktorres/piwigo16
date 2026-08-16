<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Categories\DeleteHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\DeleteHandler -- `pwg.categories.delete`
 * (admin_only, post_only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php (Comments).
 *
 * Checks `CsrfService::getToken() !== $input->pwgToken` as its very
 * first statement, before touching `categoryService` at all -- a wrong
 * token is therefore a cheap, DB-free 403 branch. The real category
 * deletion needs real category rows and is not attempted here.
 */
function pwgCategoriesDeleteHandlerTestSubject(): DeleteHandler
{
    $handler = Kernel::container()->get(DeleteHandler::class);
    if (! $handler instanceof DeleteHandler) {
        throw new LogicException('Container returned an unexpected type for ' . DeleteHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgCategoriesDeleteHandlerTestSubject();

    $result = $handler([
        'category_id' => '1',
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
