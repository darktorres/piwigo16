<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Controller\AboutController;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\AboutController -- renders the about page: no POST
 * handling, no redirects (see the class's own docblock). No dedicated
 * Integration/Browser spec of its own.
 *
 * `checkStatus(AccessLevel::Guest)` almost never denies, so this covers
 * the real happy path -- composed entirely from building blocks this
 * campaign already proved tractable individually: `MenubarRenderer`'s own
 * `guestAccess(false)` cheap-skip trick (see its own test), a stub
 * `header.latte` (see `PageHeaderRendererTest.php`), and `PageTail`'s own
 * update-check/telemetry no-op config guards + real `ConfigService`/
 * `CurrentLogger` setup (see `PageTailTest.php`). Resolved via
 * `Kernel::container()->get()` -- 15 constructor deps, none hand-built.
 */
function aboutControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? aboutControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('invoke renders the real about page for a guest visitor, with menubar/update-check/telemetry all safely no-op\'d', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-about-controller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';
        CurrentConfigTestFactory::get()->menubarFilterIcon = false;
        CurrentConfigTestFactory::get()->updateNotifyCheckPeriod = 0;
        CurrentConfigTestFactory::get()->sendPiwigoInfos = false;

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
            CurrentConfigTestFactory::get(),
        ));

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(2),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Guest,
            enabledHigh: false,
        ));

        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'about.latte', 'about_rendered=yes');
        file_put_contents($tplDir . 'header.latte', 'title={$PAGE_TITLE}');
        file_put_contents($tplDir . 'menubar.latte', 'menubar_rendered=yes');
        file_put_contents($tplDir . 'footer.latte', 'version={$VERSION}');
        $template->setTemplateDir($tplDir);

        $controller = Kernel::container()->get(AboutController::class);
        if (! $controller instanceof AboutController) {
            throw new LogicException('Container returned an unexpected type for ' . AboutController::class);
        }

        $response = $controller(new ServerRequest('GET', '/about.php'));

        expect($response->getStatusCode())
            ->toBe(200)
            ->and((string) $response->getBody())
            ->toContain('about_rendered=yes')
            ->and($template->getTemplateVars('PAGE_TITLE'))
            ->toBe('About Piwigo');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentConfigServiceTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        aboutControllerTestRrmdir($root);
    }
});
