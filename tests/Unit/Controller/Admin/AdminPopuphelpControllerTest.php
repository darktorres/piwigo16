<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\AdminPopuphelpController;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\AdminPopuphelpController -- 6 constructor
 * deps, no dedicated Integration/Browser spec of its own.
 *
 * Covers 2 branches:
 * - `?output=content_only` with a valid page: skips both
 *   PageHeaderRenderer::render() AND Bootstrap\PageTail::renderToString()
 *   entirely (returns $help_content directly, before $template->parse()
 *   is ever called) -- the cheapest real path through this controller.
 * - An invalid `?page=` value with the default (non-content_only)
 *   output: needs PageHeaderRenderer::render() first (already
 *   independently covered, cheap), then throws before ever reaching
 *   PageTail.
 * The default-output, valid-page happy path is not covered -- it
 * continues into the same Bootstrap\PageTail::renderToString() wall
 * already deferred for PopuphelpController.
 */
function adminPopuphelpTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-admin-popuphelp-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function adminPopuphelpTestRrmdir(string $dir): void
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
        is_dir($path) ? adminPopuphelpTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function adminPopuphelpTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('__invoke returns the raw help content directly for output=content_only, skipping the header/tail chrome', function (): void {
    $root = adminPopuphelpTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $controller = new AdminPopuphelpController(
            LangTestFactory::get(),
            adminPopuphelpTestAccessControl(),
            new EventDispatcher(),
            new PageState(),
            CurrentTemplateTestFactory::get(),
            CurrentConfigTestFactory::get(),
        );

        $response = $controller(new ServerRequest('GET', '/admin/popuphelp.php?page=&output=content_only'));

        expect($response->getStatusCode())
            ->toBe(200)
            ->and((string) $response->getBody())
            ->toBe('');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        adminPopuphelpTestRrmdir($root);
    }
});

test('__invoke returns a 400 "Request rejected" response for an invalid page value with the default output', function (): void {
    $root = adminPopuphelpTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'header.latte', 'header');
        $template->setTemplateDir($tplDir);

        $controller = new AdminPopuphelpController(
            LangTestFactory::get(),
            adminPopuphelpTestAccessControl(),
            new EventDispatcher(),
            new PageState(),
            CurrentTemplateTestFactory::get(),
            CurrentConfigTestFactory::get(),
        );

        $exception = null;
        try {
            $controller(new ServerRequest('GET', '/admin/popuphelp.php?page=not-valid-123'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        $response = $exception->response();
        expect($response->getStatusCode())
            ->toBe(400)
            ->and((string) $response->getBody())
            ->toBe('Request rejected: invalid page parameter');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        adminPopuphelpTestRrmdir($root);
    }
});
