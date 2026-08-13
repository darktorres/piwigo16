<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Menu\MenubarRenderer -- builds the main menubar's blocks. No
 * dedicated Integration/Browser spec of its own (reached transitively by
 * every Browser test that loads a themed page).
 *
 * `render()` itself has no early guard, but `BlockManager::
 * loadRegisteredBlocks()` only ever populates `registered_blocks` via
 * a `BlockManagerRegisterBlocks` notify's own listeners calling
 * `registerBlock()` back -- a bare, freshly-booted test container has
 * no plugin/listener registered to do that, so `display_blocks` stays
 * empty regardless of the guest-access config value. Every block
 * section is gated behind `$menu->getBlock($id) !== null`, so this
 * deterministically skips the real DB-backed `TagService::
 * getAvailableTags()`/`CategoryService::getCategoriesMenu()` calls
 * entirely, leaving only the pure identification-vars computation and a
 * real (but block-free) `BlockManager::apply()` template parse.
 */
function menubarRendererTestSubject(): MenubarRenderer
{
    $subject = Kernel::container()->get(MenubarRenderer::class);
    if (! $subject instanceof MenubarRenderer) {
        throw new LogicException('Container returned an unexpected type for ' . MenubarRenderer::class);
    }

    return $subject;
}

function menubarRendererTestRrmdir(string $dir): void
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
        is_dir($path) ? menubarRendererTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('render skips every real DB-backed block when no listener has registered any block', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-menubar-renderer-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';
        CurrentConfigTestFactory::get()->menubarFilterIcon = false;

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
        file_put_contents($tplDir . 'menubar.latte', 'menubar_rendered=yes');
        $template->setTemplateDir($tplDir);

        $renderer = menubarRendererTestSubject();

        $lang = Kernel::container()->get(Lang::class);
        $accessLevelChecker = Kernel::container()->get(AccessLevelChecker::class);
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        $filterState = Kernel::container()->get(FilterState::class);
        $sectionContextRegistry = Kernel::container()->get(SectionContextRegistry::class);
        $sessionService = Kernel::container()->get(SessionService::class);
        $deploymentPolicy = Kernel::container()->get(DeploymentPolicy::class);
        $currentUser = Kernel::container()->get(CurrentUser::class);
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        $translator = Kernel::container()->get(Translator::class);
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        $permissionService = Kernel::container()->get(PermissionService::class);
        $entityManager = Kernel::container()->get(EntityManagerInterface::class);

        if (! $lang instanceof Lang
            || ! $accessLevelChecker instanceof AccessLevelChecker
            || ! $urlService instanceof UrlServiceInterface
            || ! $filterState instanceof FilterState
            || ! $sectionContextRegistry instanceof SectionContextRegistry
            || ! $sessionService instanceof SessionService
            || ! $deploymentPolicy instanceof DeploymentPolicy
            || ! $currentUser instanceof CurrentUser
            || ! $currentTemplate instanceof CurrentTemplate
            || ! $currentConfig instanceof CurrentConfig
            || ! $eventDispatcher instanceof EventDispatcher
            || ! $translator instanceof Translator
            || ! $currentLogger instanceof CurrentLogger
            || ! $permissionService instanceof PermissionService
            || ! $entityManager instanceof EntityManagerInterface
        ) {
            throw new LogicException('Container returned an unexpected type for one of render()\'s method params.');
        }

        $countCategories = $renderer->render(
            $lang,
            $accessLevelChecker,
            $urlService,
            $filterState,
            $sectionContextRegistry,
            $sessionService,
            $deploymentPolicy,
            $currentUser,
            $currentTemplate,
            $currentConfig,
            $eventDispatcher,
            $translator,
            $currentLogger,
            $permissionService,
            $entityManager,
        );

        // BlockManager::apply()'s assignVarFromTemplate() wraps MENUBAR in
        // Latte\Runtime\Html, not a plain string.
        $menubar = $template->getTemplateVars('MENUBAR');
        expect($menubar)
            ->toBeInstanceOf(Latte\Runtime\Html::class);
        if (! $menubar instanceof Latte\Runtime\Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($countCategories)
            ->toBeNull()
            ->and((string) $menubar)
            ->toContain('menubar_rendered=yes');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        menubarRendererTestRrmdir($root);
    }
});
