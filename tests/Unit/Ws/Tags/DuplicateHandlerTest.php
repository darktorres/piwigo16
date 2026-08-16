<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Tags\DuplicateHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Tags\DuplicateHandler -- `pwg.tags.duplicate` (admin_only,
 * post_only). Resolved via `Kernel::container()->get()`, same rationale
 * as GetListHandlerTest.php (Comments).
 *
 * Checks `CsrfService::getToken() !== $input->pwgToken` as its very
 * first statement, before touching `tagService` at all -- a wrong token
 * is therefore a cheap, DB-free 403 branch. The real duplicate needs a
 * real tag row and is not attempted here.
 */
function pwgTagsDuplicateHandlerTestSubject(): DuplicateHandler
{
    $handler = Kernel::container()->get(DuplicateHandler::class);
    if (! $handler instanceof DuplicateHandler) {
        throw new LogicException('Container returned an unexpected type for ' . DuplicateHandler::class);
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
    $handler = pwgTagsDuplicateHandlerTestSubject();

    $result = $handler([
        'tag_id' => 1,
        'copy_name' => 'copy',
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
