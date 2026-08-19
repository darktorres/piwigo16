<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Admin\CommentsPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Renderer;
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
 * Piwigo\Admin\CommentsPageRenderer -- zero-constructor,
 * method-param-injected (B3 Tier 1 shape). No dedicated Integration/
 * Browser spec -- reached only via the "comments" page slug (real
 * moderation is a client-side /api/v1/comments AJAX flow this class never
 * touches).
 *
 * Tabsheet::select('', ...) needs CoreTabs::addCoreTabs() registered as
 * a TabsheetBeforeSelect handler on the same EventDispatcher, same real
 * dependency UserActivityPageRendererTest.php already established --
 * CoreTabs::addCoreTabs()'s own 'comments' case populates $sheets[''],
 * matching the empty-string tab id this renderer selects.
 */
function commentsPageTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-comments-page-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function commentsPageTestRrmdir(string $dir): void
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
        is_dir($path) ? commentsPageTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function commentsPageTestAccessControl(): AccessControl
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

test('render() assigns the comments page context and tabsheet without any real tabs', function (): void {
    $root = commentsPageTestRoot();
    session_id(str_replace('.', '-', uniqid('comments-page-test-', true)));
    CurrentConfigTestFactory::get()->secretKey = 'test-secret-key';

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'comments.latte', 'csrf={$csrfToken}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        new CommentsPageRenderer()
            ->render(LangTestFactory::get(), commentsPageTestAccessControl(), UrlServiceTestFactory::build(), $coreTabs, CurrentTemplateTestFactory::get(), $eventDispatcher, new CsrfService(CurrentConfigTestFactory::get()), new Renderer(CurrentTemplateTestFactory::get()));

        // Renderer::render() wraps its result in Latte\Runtime\Html.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Html::class);
        if (! $adminContent instanceof Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('ADMIN_PAGE_TITLE'))
            ->toBe('User comments');
        expect((string) $adminContent)
            ->toStartWith('csrf=')
            ->and((string) $adminContent)
            ->not->toBe('csrf=');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        commentsPageTestRrmdir($root);
    }
});
