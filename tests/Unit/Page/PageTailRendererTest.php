<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Page;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Page\PageTailRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Page\PageTailRenderer -- has 8 real constructor deps, but none
 * are heavy: every one is already an established pattern from earlier in
 * this campaign. No dedicated Integration/Browser spec of its own --
 * reached only as a shared page-chrome helper from every real page
 * render.
 *
 * A guest user skips the real UserRepository::getWebmasterMailAddress()
 * DB call (only reached for a non-guest); a fresh, un-configured
 * CurrentConfig skips the mobile-theme-toggle/debug-output branches
 * (mobilTheme()/showQueries()/showGt() all default falsy) -- the
 * cheapest real happy path, matching this campaign's established
 * scoping.
 */
function pageTailTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-page-tail-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pageTailTestRrmdir(string $dir): void
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
        is_dir($path) ? pageTailTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('renderToString() returns the parsed footer output and always sends telemetry for a guest, skipping the webmaster-mail DB lookup', function (): void {
    $root = pageTailTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'footer.tpl', 'version={$VERSION}');
        $template->setTemplateDir($tplDir);

        $currentConfig = new CurrentConfig();
        $currentUser = CurrentUserTestFactory::get();
        $currentUser->set(new User(
            id: UserId::from(2),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Guest,
            enabledHigh: false,
        ));
        $conn = DbConnection::build();
        $telemetrySender = new PageTailTestFakeTelemetrySender();

        $renderer = new PageTailRenderer(
            new AccessLevelChecker($currentUser, $currentConfig),
            $telemetrySender,
            UrlServiceTestFactory::build(),
            new EventDispatcher(),
            new PageState(),
            CurrentTemplateTestFactory::get(),
            $currentConfig,
            new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $currentConfig),
        );

        $output = $renderer->renderToString(microtime(true));

        expect($telemetrySender->sendWasCalled)
            ->toBeTrue()
            ->and($template->getTemplateVars('contactMail'))
            ->toBeNull()
            ->and($template->getTemplateVars('toggleMobileThemeUrl'))
            ->toBeNull()
            ->and($output)
            ->toContain('version=');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        pageTailTestRrmdir($root);
    }
});
