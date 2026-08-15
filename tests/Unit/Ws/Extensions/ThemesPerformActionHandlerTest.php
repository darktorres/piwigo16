<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Extensions\ThemesPerformActionHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Extensions\ThemesPerformActionHandler --
 * `pwg.themes.performAction` (admin_only). Resolved via
 * `Kernel::container()->get()`, same rationale as
 * PluginsPerformActionHandlerTest.php.
 *
 * Covers the CSRF-mismatch 403 (its very first check, before the
 * webmaster/config guards) and, separately, the "not webmaster" 403 with a
 * real, matching CSRF token (SEC finding 4 -- this method used to have no
 * webmaster-status guard of its own, unlike PluginsPerformActionHandler)
 * -- both avoid ever reaching `ExtensionScanner::scan()`/
 * `ExtensionLifecycle::performAction()` (real filesystem scans + writes).
 */
function pwgThemesPerformActionHandlerTestSubject(): ThemesPerformActionHandler
{
    $handler = Kernel::container()->get(ThemesPerformActionHandler::class);
    if (! $handler instanceof ThemesPerformActionHandler) {
        throw new LogicException('Container returned an unexpected type for ' . ThemesPerformActionHandler::class);
    }

    return $handler;
}

function pwgThemesPerformActionHandlerTestSetUser(bool $isWebmaster): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from($isWebmaster ? 1 : 2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $isWebmaster ? UserStatus::Webmaster : UserStatus::Admin,
        enabledHigh: false,
    ));
}

/**
 * Same uniqid()-based, per-process session id rationale as
 * Piwigo\Tests\Unit\Csrf\CsrfServiceTest.php's own csrfTestSessionId() --
 * avoids the shared /var/lib/php/sessions file-lock collision across
 * concurrent worktree test runs a literal hardcoded id would risk.
 */
function pwgThemesPerformActionHandlerTestSessionId(): string
{
    /** @var string|null */
    static $id = null;
    $id ??= str_replace('.', '-', uniqid('themes-perform-action-test-', true));

    return $id;
}

/**
 * PHP refuses to change session_id() once a session is already active --
 * under the full parallel suite, an earlier test in the same worker
 * process can leave a real session open, same guard as
 * Tests\Unit\Ws\WsHelperTest.php's own wsHelperTestSetSessionId().
 */
function pwgThemesPerformActionHandlerTestRealToken(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id(pwgThemesPerformActionHandlerTestSessionId());
    CurrentConfigTestFactory::get()->secretKey = 'themes-perform-action-test-secret';

    return hash_hmac('sha256', pwgThemesPerformActionHandlerTestSessionId(), 'themes-perform-action-test-secret');
}

function pwgThemesPerformActionHandlerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extensions-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pwgThemesPerformActionHandlerTestRrmdir(string $dir): void
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
        is_dir($path) ? pwgThemesPerformActionHandlerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $root = pwgThemesPerformActionHandlerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $handler = pwgThemesPerformActionHandlerTestSubject();
        $server = Kernel::container()->get(Server::class);
        if (! $server instanceof Server) {
            throw new LogicException('Container returned an unexpected type for ' . Server::class);
        }

        $result = $handler([
            'action' => 'activate',
            'theme' => 'my_theme',
            'pwg_token' => 'wrong-token',
        ], $server);

        expect($result)
            ->toBeInstanceOf(WsErrorResponse::class);
        if ($result instanceof WsErrorResponse) {
            expect($result->code())
                ->toBe(403);
        }
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgThemesPerformActionHandlerTestRrmdir($root);
    }
});

test('returns a 403 WsErrorResponse when the user is not a webmaster, for a real, matching CSRF token', function (): void {
    $root = pwgThemesPerformActionHandlerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        pwgThemesPerformActionHandlerTestSetUser(isWebmaster: false);

        $handler = pwgThemesPerformActionHandlerTestSubject();
        $server = Kernel::container()->get(Server::class);
        if (! $server instanceof Server) {
            throw new LogicException('Container returned an unexpected type for ' . Server::class);
        }

        $result = $handler([
            'action' => 'activate',
            'theme' => 'my_theme',
            'pwg_token' => pwgThemesPerformActionHandlerTestRealToken(),
        ], $server);

        expect($result)
            ->toBeInstanceOf(WsErrorResponse::class);
        if ($result instanceof WsErrorResponse) {
            expect($result->code())
                ->toBe(403)
                ->and($result->message())
                ->toBe('Webmaster status is required.');
        }
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgThemesPerformActionHandlerTestRrmdir($root);
    }
});
