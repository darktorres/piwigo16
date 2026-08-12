<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\WsController;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use ReflectionProperty;

/**
 * Piwigo\Controller\WsController -- the web-service dispatcher entry
 * point. No dedicated Integration/Browser spec of its own (Contract tests
 * reach the real Ws\* method classes over HTTP, not this controller).
 *
 * `checkStatus(AccessLevel::Free)` (the lowest access tier) never denies
 * any real user, and the real happy path always ends in a literal
 * `exit()` after `Server::run()` (see the class's own docblock) --
 * genuinely un-testable normally. But `allowWebServices = false` reaches
 * `HtmlService::pageForbidden()` BEFORE either of those, which throws via
 * `RedirectService::redirectHtml()` -- the SAME expensive-looking
 * "wall" U3 originally deferred `PictureCoiSubController` for, now
 * composed from pieces this session already proved tractable
 * individually: `PageHeaderRenderer` (stub `header.latte`, this time
 * exercising its own refresh-meta branch too) and `PageTail::
 * renderToString()` (update-check/telemetry no-op guards + real
 * `ConfigService`/`CurrentLogger`). `Lang::setLangInfo()` is called
 * directly to mark lang info already initialized, skipping
 * `redirectHtml()`'s own "early crash fallback" branch (which would
 * otherwise need a real `UserService::buildUser()` DB read) -- a fresh
 * `AdminContext` defaults to inactive, so the "already admin" template
 * rebuild branch is skipped too.
 */
function wsControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? wsControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('invoke returns a real 403 forbidden page when web services are disabled', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-ws-controller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';
        // allowWebServices is private(set) (SchemaIntegrityTest enforces
        // it) -- same ReflectionProperty::setValue() pattern
        // UserServiceTest already uses for a private(set) CurrentConfig
        // property, since the real hydration path (ConfigService::
        // hydrate()) itself always writes through Reflection too.
        new ReflectionProperty(CurrentConfig::class, 'allowWebServices')->setValue(CurrentConfigTestFactory::get(), false);
        CurrentConfigTestFactory::get()->updateNotifyCheckPeriod = 0;
        CurrentConfigTestFactory::get()->sendPiwigoInfos = false;

        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }
        $lang->setLangInfo([
            'code' => 'en_UK',
        ]);

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
            theme: ThemeId::from('default'),
            status: UserStatus::Guest,
            enabledHigh: false,
        ));

        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'header.latte', 'title={$PAGE_TITLE}');
        file_put_contents($tplDir . 'redirect.latte', 'redirect_rendered=yes');
        file_put_contents($tplDir . 'footer.latte', 'version={$VERSION}');
        $template->setTemplateDir($tplDir);

        $controller = Kernel::container()->get(WsController::class);
        if (! $controller instanceof WsController) {
            throw new LogicException('Container returned an unexpected type for ' . WsController::class);
        }

        $exception = null;
        try {
            $controller(new ServerRequest('GET', '/ws.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($exception->response()->getStatusCode())
            ->toBe(403)
            ->and((string) $exception->response()->getBody())
            ->toContain('redirect_rendered=yes');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentConfigServiceTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        wsControllerTestRrmdir($root);
    }
});
