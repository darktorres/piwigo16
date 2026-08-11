<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\RatingSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\RatingSubController -- 8 constructor deps, a
 * pure delegate to `RatingPageRenderer::render()`. Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser spec
 * of its own.
 *
 * `RatingPageRenderer::render()` itself calls
 * `AccessControl::checkStatus(AccessLevel::Administrator)` right after
 * grabbing the template, before any Tabsheet/`piwigo_rate` access --
 * same reusable "logged-in, non-guest, non-admin CurrentUser" 401
 * branch as `PhotoSubControllerTest.php`. The real rated-photos report
 * needs `RateRepository` reads against the shared `piwigo_rate` table
 * (see feedback_unit_suite_cross_file_db_race_pattern) and is not
 * attempted here.
 */
function ratingSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-rating-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function ratingSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? ratingSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = ratingSubControllerTestRoot();

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

        $subController = Kernel::container()->get(RatingSubController::class);
        if (! $subController instanceof RatingSubController) {
            throw new LogicException('Container returned an unexpected type for ' . RatingSubController::class);
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
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        ratingSubControllerTestRrmdir($root);
    }
});
