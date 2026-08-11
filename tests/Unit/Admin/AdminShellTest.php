<?php

declare(strict_types=1);

use Piwigo\Admin\AdminShell;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
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
 * Piwigo\Admin\AdminShell -- the real admin.php page-shell dispatcher
 * (routes every `?page=` slug to its own SubController). Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 21 constructor deps, none touched
 * by the guard branch under test.
 *
 * `run()` itself always catches `ResponseReadyException` and emits it
 * via a real `ResponseEmitter` (real `header()`/`echo` calls, invisible
 * under the CLI SAPI -- see this campaign's own established finding),
 * so this reaches the private `runDispatch()` method directly via
 * Reflection instead, to assert on the real thrown exception/response
 * rather than its silently-emitted side effect. `runDispatch()` itself
 * registers `CoreTabs::addCoreTabs()` onto the event dispatcher and
 * fires a `LocBeginAdmin` notify (both harmless no-ops with nothing
 * downstream listening) before reaching `AccessControl::checkStatus(
 * AccessLevel::Administrator)` -- same reusable "logged-in, non-guest,
 * non-admin CurrentUser" 401 branch as `PhotoSubControllerTest.php`,
 * reached before any of the filesystem-check/direct-action/page-dispatch
 * logic that follows it in this ~430-line method.
 */
function adminShellTestRrmdir(string $dir): void
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
        is_dir($path) ? adminShellTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('runDispatch denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-admin-shell-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';

        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(999),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $adminShell = Kernel::container()->get(AdminShell::class);
        if (! $adminShell instanceof AdminShell) {
            throw new LogicException('Container returned an unexpected type for ' . AdminShell::class);
        }

        $method = new ReflectionMethod(AdminShell::class, 'runDispatch');

        $exception = null;
        try {
            $method->invoke($adminShell);
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
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        adminShellTestRrmdir($root);
    }
});
