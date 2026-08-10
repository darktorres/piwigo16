<?php

declare(strict_types=1);

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\CatOptionsSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\CatOptionsSubController -- 1 constructor dep, a
 * pure delegate to `CatOptionsPageRenderer::render()`. Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser spec
 * of its own.
 *
 * Reclassified out of U3's original "7 thin SubControllers" T1 bucket
 * (see project memory). `CatOptionsPageRenderer::render()` calls
 * `AccessControl::checkStatus(AccessLevel::Administrator)` right after
 * grabbing the template -- same reusable "logged-in, non-guest,
 * non-admin CurrentUser" 401 branch as `PhotoSubControllerTest.php`.
 * The real bulk category-option toggling needs real category rows and
 * is not attempted here.
 */
function catOptionsSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-cat-options-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function catOptionsSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? catOptionsSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = catOptionsSubControllerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(999),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: '',
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $subController = Kernel::container()->get(CatOptionsSubController::class);
        if (! $subController instanceof CatOptionsSubController) {
            throw new LogicException('Container returned an unexpected type for ' . CatOptionsSubController::class);
        }

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($exception->response()->getStatusCode())->toBe(401)
            ->and((string) $exception->response()->getBody())->toContain('You are not authorized to access the requested page');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        catOptionsSubControllerTestRrmdir($root);
    }
});
