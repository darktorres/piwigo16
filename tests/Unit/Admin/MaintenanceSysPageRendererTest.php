<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\MaintenanceSysPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
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
 * Piwigo\Admin\MaintenanceSysPageRenderer -- zero-constructor,
 * method-param-injected (B3 Tier 1 shape). No dedicated Integration/
 * Browser spec -- reached only via the "sys" tab of the "maintenance"
 * page slug.
 *
 * The activity log is server-rendered directly, no ajax
 * round-trip -- a webmaster always gets a real
 * findSystemObjectLogWithUsernames() query result (the fixture DB's own
 * install-time system log, 3 rows), a non-webmaster gets none.
 */
function maintenanceSysTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-maintenance-sys-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function maintenanceSysTestEntityManager(): EntityManagerInterface
{
    $entityManager = Kernel::container()->get(EntityManagerInterface::class);
    if (! $entityManager instanceof EntityManagerInterface) {
        throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
    }

    return $entityManager;
}

function maintenanceSysTestRrmdir(string $dir): void
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
        is_dir($path) ? maintenanceSysTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function maintenanceSysTestAccessControl(UserStatus $status): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $status,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('render() adds a warning and skips the webmaster-only content for a non-webmaster', function (): void {
    $root = maintenanceSysTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'maintenance_sys.latte', 'isWebmaster={$isWebmaster}');
        $template->setTemplateDir($tplDir);
        $pageState = new PageState();

        new MaintenanceSysPageRenderer()
            ->render(LangTestFactory::get(), maintenanceSysTestAccessControl(UserStatus::Admin), [], $pageState, CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), maintenanceSysTestEntityManager());

        expect($pageState->warnings)
            ->toHaveCount(1)
            ->and($pageState->warnings[0])->toContain('status is required to edit parameters.')
            ->and($template->getTemplateVars('isWebmaster'))
            ->toBe(0)
            // a non-webmaster never even queries the activity log.
            ->and($template->getTemplateVars('ACTIVITY_LOG_ENTRIES'))
            ->toBe([]);
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        maintenanceSysTestRrmdir($root);
    }
});

test('render() adds no warning for a webmaster and passes the real system activity log to the template', function (): void {
    $root = maintenanceSysTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'maintenance_sys.latte', 'isWebmaster={$isWebmaster}');
        $template->setTemplateDir($tplDir);
        $pageState = new PageState();

        new MaintenanceSysPageRenderer()
            ->render(LangTestFactory::get(), maintenanceSysTestAccessControl(UserStatus::Webmaster), [], $pageState, CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), maintenanceSysTestEntityManager());

        $rawEntries = $template->getTemplateVars('ACTIVITY_LOG_ENTRIES');
        $entries = is_array($rawEntries) ? $rawEntries : [];

        expect($pageState->warnings)
            ->toBe([])
            ->and($template->getTemplateVars('isWebmaster'))
            ->toBe(1)
            ->and($rawEntries)
            ->toBeArray()
            // At least the fixture DB's own install-time system log (Core
            // install + 2 default-theme activations, see
            // tests/Fixtures/piwigo-17.0.sql) -- not an exact count, since
            // other tests sharing this DB can add their own real system
            // activity rows (plugin/theme actions, etc.) within the same
            // session.
            ->and(count($entries))
            ->toBeGreaterThanOrEqual(3);

        $first = is_array($entries[0] ?? null) ? $entries[0] : [];
        expect($first)
            ->toHaveKeys(['id', 'object', 'action', 'username', 'date', 'hour', 'detailItems', 'detailArrow']);
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        maintenanceSysTestRrmdir($root);
    }
});
