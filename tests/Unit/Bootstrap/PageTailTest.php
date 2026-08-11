<?php

declare(strict_types=1);

use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Bootstrap\PageTail -- the page-footer orchestration (the
 * "check for Piwigo updates" notification block, then the real
 * PageTailRenderer render itself). No dedicated Integration/Browser
 * spec of its own.
 *
 * `renderToString()`'s own `checkForUpdates()` call is made a safe
 * no-op via `updateNotifyCheckPeriod(0)` (its own first guard) --
 * without it, a fresh (never-checked) install reaches a real
 * `UniqueExecLock::begins()` + `CoreUpdateService::
 * notifyPiwigoNewVersions()` real network call. The real
 * `PiwigoInfosSender::send()` this method also always constructs is
 * made a safe no-op via `sendPiwigoInfos(false)` (its own first
 * config guard, after a real `CurrentLogger::get()` call this test
 * initializes with a no-op Logger) -- both config guards are the
 * established "cheap real branch" pattern used throughout this
 * campaign. A guest `CurrentUser` (container-shared, same pattern as
 * `PageTailRendererTest.php`) skips `PageTailRenderer`'s own real
 * webmaster-mail DB lookup.
 */
function pageTailBootstrapTestRrmdir(string $dir): void
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
        is_dir($path) ? pageTailBootstrapTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('renderToString returns the real parsed footer output, with update-check and telemetry both safely no-op\'d', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-page-tail-bootstrap-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->setDataLocation('data/');
        CurrentConfigTestFactory::get()->setDataDirChecked('1');
        CurrentConfigTestFactory::get()->setUpdateNotifyCheckPeriod(0);
        CurrentConfigTestFactory::get()->setSendPiwigoInfos(false);

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));

        $conn = DbConnection::build();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService(
            EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class),
            new EventDispatcher(),
            CurrentConfigTestFactory::get(),
        ));

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(2),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: '',
            status: UserStatus::Guest,
            enabledHigh: false,
        ));

        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'footer.tpl', 'version={$VERSION}');
        $template->set_template_dir($tplDir);

        $output = PageTail::renderToString();

        expect($output)
            ->toContain('version=');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentConfigServiceTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        pageTailBootstrapTestRrmdir($root);
    }
});
