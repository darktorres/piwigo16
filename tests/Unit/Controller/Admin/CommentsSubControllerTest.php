<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\CommentsSubController;
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

/**
 * Piwigo\Controller\Admin\CommentsSubController -- a genuinely thin
 * delegate: all 7 constructor deps are standard, already-factory-covered
 * types (B3 Tier 2's real shape, unlike the Tier-1-misclassified
 * SubControllers that each wrap one heavily-constructed renderer -- see
 * project memory for that finding). No dedicated Integration/Browser
 * spec of its own -- reached only via the "comments" page slug.
 *
 * Reuses the exact CommentsPageRenderer construction/assertions
 * CommentsPageRendererTest.php already established, just reached through
 * handle() instead of a direct render() call, proving the delegation
 * itself.
 */
function commentsSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-comments-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function commentsSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? commentsSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function commentsSubControllerTestAccessControl(): AccessControl
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

test('handle() delegates to CommentsPageRenderer::render() with every one of its own constructor deps', function (): void {
    $root = commentsSubControllerTestRoot();
    session_id(str_replace('.', '-', uniqid('comments-subcontroller-test-', true)));
    CurrentConfigTestFactory::get()->secretKey = 'test-secret-key';

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'comments.latte', 'title={$ADMIN_PAGE_TITLE}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = new CommentsSubController(
            LangTestFactory::get(),
            commentsSubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            CurrentTemplateTestFactory::get(),
            $eventDispatcher,
            CurrentConfigTestFactory::get(),
        );

        $subController->handle(new ServerRequest('GET', '/admin.php'));

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Latte\Runtime\Html::class);
        if (! $adminContent instanceof Latte\Runtime\Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('ADMIN_PAGE_TITLE'))
            ->toBe('User comments')
            ->and($template->getTemplateVars('F_ACTION'))
            ->toContain('admin.php?page=comments')
            ->and((string) $adminContent)
            ->toBe('title=User comments');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        commentsSubControllerTestRrmdir($root);
    }
});
