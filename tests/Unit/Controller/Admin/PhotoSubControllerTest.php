<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\PhotoSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\PhotoSubController -- 13 constructor deps.
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- every dep beyond `CurrentTemplate`/
 * `CurrentConfig`/the container-shared `CurrentUser` is untouched on
 * this guard path. No dedicated Integration/Browser spec of its own.
 *
 * Covers `AccessControl::checkStatus(AccessLevel::Administrator)`'s own
 * denial branch -- the first real logic in `handle()` after grabbing the
 * template. A logged-in, non-guest, non-admin (`UserStatus::Normal`)
 * container-shared `CurrentUser` reaches `HtmlService::accessDenied()`'s
 * "not a guest" branch, which throws a 401 `ResponseReadyException`
 * directly (no redirect dispatch, no DB read of the requested photo).
 * The 3 real tabs (properties/coi/formats) all need a real image row
 * and are not attempted here.
 */
function photoSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-photo-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function photoSubControllerTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? photoSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = photoSubControllerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(999),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $subController = Kernel::container()->get(PhotoSubController::class);
        if (! $subController instanceof PhotoSubController) {
            throw new LogicException('Container returned an unexpected type for ' . PhotoSubController::class);
        }

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($exception->response()->getStatusCode())
            ->toBe(401)
            ->and((string) $exception->response()->getBody())
            ->toContain('You are not authorized to access the requested page');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        photoSubControllerTestRrmdir($root);
    }
});
