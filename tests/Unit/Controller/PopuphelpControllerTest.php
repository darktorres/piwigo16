<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\PopuphelpController;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\PopuphelpController -- 6 constructor deps, no
 * dedicated Integration/Browser spec of its own.
 *
 * Only the invalid-?page= 400 branch is covered -- PageHeaderRenderer::render()
 * (already independently covered, cheap) runs unconditionally first, but
 * the real happy path continues into Bootstrap\PageTail::renderToString(),
 * which builds a real PiwigoInfosSender (13 further constructor deps,
 * several container-resolved statics needing a fully-wired admin
 * bootstrap) -- the same class of wall this campaign already hit and
 * deferred for RedirectService::redirectHtml(). The 400 branch throws
 * before ever reaching that call.
 */
function popuphelpTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-popuphelp-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function popuphelpTestRrmdir(string $dir): void
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
        is_dir($path) ? popuphelpTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function popuphelpTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('__invoke returns a 400 "Hacking attempt!" response for a page value with disallowed characters', function (): void {
    $root = popuphelpTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'header.tpl', 'header');
        $template->setTemplateDir($tplDir);

        $controller = new PopuphelpController(
            LangTestFactory::get(),
            popuphelpTestAccessControl(),
            new EventDispatcher(),
            new PageState(),
            CurrentTemplate::current(),
            CurrentConfigTestFactory::get(),
        );

        $request = new ServerRequest('GET', '/popuphelp.php?page=not-valid-123');

        $exception = null;
        try {
            $controller($request);
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
            ->toBe('Hacking attempt!');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        popuphelpTestRrmdir($root);
    }
});
