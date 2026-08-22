<?php

declare(strict_types=1);

use Piwigo\Admin\AdminShell;
use Piwigo\Admin\Request\AdminShellRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

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
 * fires an `AdminShellDispatching` notify (both harmless no-ops with
 * nothing downstream listening) before reaching `AccessControl::checkStatus(
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
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        adminShellTestRrmdir($root);
    }
});

/**
 * buildChangeThemeUrl() (P44-C) -- extracted specifically so this exact
 * regression is unit-testable without driving `runDispatch()`'s full
 * ~430-line body (real page dispatch, DB-backed sub-controllers, and all
 * -- see this file's own docblock on why that's reflected into rather
 * than run() end-to-end). `$_SERVER['QUERY_STRING']` carries the raw,
 * un-urldecoded request-line bytes -- a real HTTP client (not
 * necessarily a browser, which would percent-encode `<`/`>`/`"` in its
 * own address bar first) can legitimately send these characters
 * literally, so the fix under test is exactly "does this method hand
 * that string to its caller unmodified, trusting Latte's own
 * auto-escape at print time, instead of re-encoding only `&` and
 * leaving the rest to reach `layout.latte:77` raw."
 */
test('buildChangeThemeUrl passes the raw query string through unmodified for Latte to escape', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-admin-shell-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';

        $adminShell = Kernel::container()->get(AdminShell::class);
        if (! $adminShell instanceof AdminShell) {
            throw new LogicException('Container returned an unexpected type for ' . AdminShell::class);
        }

        $adminShellRequest = AdminShellRequest::fromArrays(
            [
                'page' => 'intro',
                'tag' => 'x"><script>alert(1)</script>',
            ],
            [],
            new InputValidator(),
        );

        $method = new ReflectionMethod(AdminShell::class, 'buildChangeThemeUrl');
        $url = $method->invoke($adminShell, $adminShellRequest, 'page=intro&tag=x"><script>alert(1)</script>');

        expect($url)
            ->toContain('page=intro&tag=x"><script>alert(1)</script>&change_theme=1')
            ->and($url)
            ->not->toContain('&amp;');
    } finally {
        Kernel::reset();
        adminShellTestRrmdir($root);
    }
});

test('buildChangeThemeUrl omits the query string entirely when an extra param besides page/section/tag is present', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-admin-shell-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';

        $adminShell = Kernel::container()->get(AdminShell::class);
        if (! $adminShell instanceof AdminShell) {
            throw new LogicException('Container returned an unexpected type for ' . AdminShell::class);
        }

        $adminShellRequest = AdminShellRequest::fromArrays(
            [
                'page' => 'intro',
                'extra' => '1',
            ],
            [],
            new InputValidator(),
        );

        $method = new ReflectionMethod(AdminShell::class, 'buildChangeThemeUrl');
        $url = $method->invoke($adminShell, $adminShellRequest, 'page=intro&extra=1');

        expect($url)
            ->toEndWith('admin.php?change_theme=1');
    } finally {
        Kernel::reset();
        adminShellTestRrmdir($root);
    }
});
