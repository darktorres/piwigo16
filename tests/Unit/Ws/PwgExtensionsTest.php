<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgExtensions;
use Piwigo\Ws\PwgServer;

/**
 * Piwigo\Ws\PwgExtensions -- the `pwg.plugins.*`/`pwg.themes.performAction`/
 * `pwg.extensions.*` WS methods (6 registrations, all admin_only).
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 17 constructor deps, none of which
 * are touched by the guard branches under test. No dedicated
 * Integration/Browser spec of its own.
 *
 * Each method's own cheapest, first-checked guard is covered here,
 * picked to avoid ever reaching `ExtensionScanner::scan()`/
 * `ExtensionLifecycle::performAction()` (real filesystem scans + writes)
 * or `ExtensionUpdateChecker`/`CoreUpdateService` (real PEM/core-update
 * network calls, the same wall `UpdatesSubControllerTest.php` already
 * documents): `pluginsPerformAction()`/`themesPerformAction()`'s
 * CSRF-mismatch 403 (their very first check, before the webmaster/
 * config guards); `update()`'s "extensions install disabled" 401 (its
 * very first check, before webmaster/CSRF); `ignoreUpdate()`'s
 * "not webmaster" 401 (its very first check) and, separately, its
 * CSRF-mismatch 403 (reached with a real webmaster user). `pluginsGetList()`/
 * `checkUpdates()` have no guard of their own at all and are not
 * attempted here.
 */
function pwgExtensionsTestSubject(): PwgExtensions
{
    $ws = Kernel::container()->get(PwgExtensions::class);
    if (! $ws instanceof PwgExtensions) {
        throw new LogicException('Container returned an unexpected type for ' . PwgExtensions::class);
    }

    return $ws;
}

function pwgExtensionsTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extensions-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function pwgExtensionsTestRrmdir(string $dir): void
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
        is_dir($path) ? pwgExtensionsTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function pwgExtensionsTestSetUser(bool $isWebmaster): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from($isWebmaster ? 1 : 2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: '',
        status: $isWebmaster ? UserStatus::Webmaster : UserStatus::Admin,
        enabledHigh: false,
    ));
}

test('pluginsPerformAction returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $root = pwgExtensionsTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $ws = pwgExtensionsTestSubject();
        $server = Kernel::container()->get(PwgServer::class);
        if (! $server instanceof PwgServer) {
            throw new LogicException('Container returned an unexpected type for ' . PwgServer::class);
        }

        $result = $ws->pluginsPerformAction([
            'action' => 'activate',
            'plugin' => 'my_plugin',
            'pwg_token' => 'wrong-token',
        ], $server);

        expect($result)
            ->toBeInstanceOf(PwgError::class);
        if ($result instanceof PwgError) {
            expect($result->code())
                ->toBe(403);
        }
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgExtensionsTestRrmdir($root);
    }
});

test('themesPerformAction returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $root = pwgExtensionsTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $ws = pwgExtensionsTestSubject();
        $server = Kernel::container()->get(PwgServer::class);
        if (! $server instanceof PwgServer) {
            throw new LogicException('Container returned an unexpected type for ' . PwgServer::class);
        }

        $result = $ws->themesPerformAction([
            'action' => 'activate',
            'theme' => 'my_theme',
            'pwg_token' => 'wrong-token',
        ], $server);

        expect($result)
            ->toBeInstanceOf(PwgError::class);
        if ($result instanceof PwgError) {
            expect($result->code())
                ->toBe(403);
        }
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgExtensionsTestRrmdir($root);
    }
});

test('update returns a 401 PwgError when extensions install is disabled', function (): void {
    $root = pwgExtensionsTestRoot();

    try {
        CurrentConfigTestFactory::get()->setEnableExtensionsInstall(false);

        $ws = pwgExtensionsTestSubject();
        $server = Kernel::container()->get(PwgServer::class);
        if (! $server instanceof PwgServer) {
            throw new LogicException('Container returned an unexpected type for ' . PwgServer::class);
        }

        $result = $ws->update([
            'type' => 'plugins',
            'id' => 'my_plugin',
            'revision' => '1',
            'pwg_token' => 'anything',
        ], $server);

        expect($result)
            ->toBeInstanceOf(PwgError::class);
        if ($result instanceof PwgError) {
            expect($result->code())
                ->toBe(401)
                ->and($result->message())
                ->toBe('Piwigo extensions install/update system is disabled');
        }
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgExtensionsTestRrmdir($root);
    }
});

test('ignoreUpdate returns a 401 PwgError when the user is not a webmaster', function (): void {
    $root = pwgExtensionsTestRoot();

    try {
        pwgExtensionsTestSetUser(isWebmaster: false);

        $ws = pwgExtensionsTestSubject();
        $server = Kernel::container()->get(PwgServer::class);
        if (! $server instanceof PwgServer) {
            throw new LogicException('Container returned an unexpected type for ' . PwgServer::class);
        }

        $result = $ws->ignoreUpdate([
            'type' => null,
            'id' => null,
            'reset' => false,
            'pwg_token' => 'anything',
        ], $server);

        expect($result)
            ->toBeInstanceOf(PwgError::class);
        if ($result instanceof PwgError) {
            expect($result->code())
                ->toBe(401)
                ->and($result->message())
                ->toBe('Access denied');
        }
    } finally {
        Kernel::reset();
        pwgExtensionsTestRrmdir($root);
    }
});

test('ignoreUpdate returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token, for a real webmaster', function (): void {
    $root = pwgExtensionsTestRoot();

    try {
        pwgExtensionsTestSetUser(isWebmaster: true);

        $ws = pwgExtensionsTestSubject();
        $server = Kernel::container()->get(PwgServer::class);
        if (! $server instanceof PwgServer) {
            throw new LogicException('Container returned an unexpected type for ' . PwgServer::class);
        }

        $result = $ws->ignoreUpdate([
            'type' => null,
            'id' => null,
            'reset' => false,
            'pwg_token' => 'wrong-token',
        ], $server);

        expect($result)
            ->toBeInstanceOf(PwgError::class);
        if ($result instanceof PwgError) {
            expect($result->code())
                ->toBe(403);
        }
    } finally {
        Kernel::reset();
        pwgExtensionsTestRrmdir($root);
    }
});
