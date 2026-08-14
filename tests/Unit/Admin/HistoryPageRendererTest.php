<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HistoryPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Admin\HistoryPageRenderer -- zero-constructor, method-param-
 * injected (B3 Tier 1 shape). No dedicated Integration/Browser spec --
 * reached only via the "history" page slug (the real filtered line
 * listing is a client-side ws.php async call this class never touches).
 *
 * Only the default (no ?filter_user_id) happy path is covered -- a real
 * $_GET['filter_user_id'] additionally needs a matching real
 * UserRepository::findUsernameById() row, a materially bigger unit of
 * setup for a single extra USER_NAME/USER_ID branch.
 *
 * Same Tabsheet::select() + CoreTabs::addCoreTabs() wiring
 * CommentsPageRendererTest.php/UserActivityPageRendererTest.php already
 * established -- CoreTabs's own 'history' case populates both 'stats'
 * and 'history' keys, matching this renderer's own $pageSlug='history'.
 */
function historyPageTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-history-page-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function historyPageTestRrmdir(string $dir): void
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
        is_dir($path) ? historyPageTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function historyPageTestAccessControl(): AccessControl
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

test('render() defaults the date range to today and skips the user-id lookup when no filter is given', function (): void {
    $root = historyPageTestRoot();
    unset($_GET['filter_ip'], $_GET['filter_image_id'], $_GET['filter_user_id'], $_COOKIE['pwg_display_thumbnail']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'history.latte', 'start={$START}|end={$END}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }

        new HistoryPageRenderer()
            ->render(LangTestFactory::get(), historyPageTestAccessControl(), 'history', UrlServiceTestFactory::build(), $coreTabs, CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), $eventDispatcher, new InputValidator(), $entityManager);

        $today = Env::now()->format('Y-m-d');

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Latte\Runtime\Html::class);
        if (! $adminContent instanceof Latte\Runtime\Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('START'))
            ->toBe($today)
            ->and($template->getTemplateVars('END'))
            ->toBe($today)
            ->and($template->getTemplateVars('USER_ID'))
            ->toBe(-1)
            ->and($template->getTemplateVars('USER_NAME'))
            ->toBeNull()
            ->and($template->getTemplateVars('display_thumbnail_selected'))
            ->toBe('no_display_thumbnail')
            ->and($template->getTemplateVars('ADMIN_PAGE_TITLE'))
            ->toBe('History')
            ->and((string) $adminContent)
            ->toBe('start=' . $today . '|end=' . $today);
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        historyPageTestRrmdir($root);
    }
});
