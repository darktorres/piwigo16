<?php

declare(strict_types=1);

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\CoreTabs;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\GroupListSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\GroupListSubController -- 1 constructor dep, a
 * pure delegate to `GroupListPageRenderer::render()`. Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser spec
 * of its own.
 *
 * Reclassified out of U3's original "7 thin SubControllers" T1 bucket
 * (see project memory). `GroupListPageRenderer::render()` sets up its
 * own `Tabsheet::select()` BEFORE calling `AccessControl::checkStatus()`
 * (unlike its 4 siblings covered elsewhere in this batch, where
 * checkStatus() comes first) -- so reaching the same reusable
 * "logged-in, non-guest, non-admin CurrentUser" 401 branch here also
 * needs `CoreTabs::addCoreTabs()` registered as a `TabsheetBeforeSelect`
 * handler on the container-shared `EventDispatcher`, same as
 * `AdminShell::runDispatch()` does once per real request (see
 * `UserActivityPageRendererTest.php` for the original precedent). The
 * real group list/rename/delete logic needs real group rows and is not
 * attempted here.
 */
function groupListSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-group-list-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function groupListSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? groupListSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() denies access with a 401 for a logged-in non-admin user', function (): void {
    $root = groupListSubControllerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        $template->set_template_dir($tplDir);

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(999),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: '',
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = Kernel::container()->get(GroupListSubController::class);
        if (! $subController instanceof GroupListSubController) {
            throw new LogicException('Container returned an unexpected type for ' . GroupListSubController::class);
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
        groupListSubControllerTestRrmdir($root);
    }
});
