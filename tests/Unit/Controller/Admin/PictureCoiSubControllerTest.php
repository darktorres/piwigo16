<?php

declare(strict_types=1);

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\PictureCoiSubController;
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
 * Piwigo\Controller\Admin\PictureCoiSubController -- 1 constructor dep, a
 * pure delegate to `PictureCoiPageRenderer::render()`. Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser spec
 * of its own.
 *
 * Reclassified out of U3's original "7 thin SubControllers" T1 bucket
 * (see project memory): its cheapest guard (missing `image_id` ->
 * `pageNotFound()`) routes through the expensive `RedirectService::
 * redirectHtml()`. But `PictureCoiPageRenderer::render()` calls
 * `AccessControl::checkStatus(AccessLevel::Administrator)` BEFORE that
 * `image_id` check -- same reusable "logged-in, non-guest, non-admin
 * CurrentUser" 401 branch as `PhotoSubControllerTest.php`, reached
 * without ever touching the `image_id`/`redirectHtml()` wall.
 */
function pictureCoiSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-picture-coi-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function pictureCoiSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? pictureCoiSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = pictureCoiSubControllerTestRoot();

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

        $subController = Kernel::container()->get(PictureCoiSubController::class);
        if (! $subController instanceof PictureCoiSubController) {
            throw new LogicException('Container returned an unexpected type for ' . PictureCoiSubController::class);
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
        pictureCoiSubControllerTestRrmdir($root);
    }
});
