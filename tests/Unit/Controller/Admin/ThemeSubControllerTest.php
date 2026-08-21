<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\ThemeSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\ThemeSubController -- 11 constructor deps
 * (dispatches to the theme's own SettingsPageInterface on the happy
 * path, then renders its returned View itself). No dedicated
 * Integration/Browser spec of its own.
 *
 * Covers the "invalid theme" fatalError() guard: a well-formed ?theme=
 * value (passes ThemeIdRequest/InputValidator's own charset check
 * cleanly) against a themes directory pointed at an empty temp dir (so
 * ExtensionScanner::scan() finds zero real themes) reaches it directly,
 * before ThemeRegistry::bootForSettingsPage() is ever called. The "no
 * settings page" fatalError() branch and the real
 * handleSettingsRequest() happy path both need a real theme directory
 * with a marker `theme.json` on disk, not attempted here.
 */
function themeSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-theme-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    return $root;
}

function themeSubControllerTestEntityManager(): EntityManagerInterface
{
    $entityManager = Kernel::container()->get(EntityManagerInterface::class);
    if (! $entityManager instanceof EntityManagerInterface) {
        throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
    }

    return $entityManager;
}

/**
 * Container-autowired, matching themeSubControllerTestEntityManager()'s
 * own style -- the "invalid theme" branch this file covers never calls a
 * method on this collaborator (handle() fatalErrors before ever reaching
 * bootForSettingsPage()), so a fully-wired, DB-backed instance isn't
 * needed for correctness, only for the constructor's own type.
 */
function themeSubControllerTestThemeRegistry(): ThemeRegistry
{
    $themeRegistry = Kernel::container()->get(ThemeRegistry::class);
    if (! $themeRegistry instanceof ThemeRegistry) {
        throw new LogicException('Container returned an unexpected type for ' . ThemeRegistry::class);
    }

    return $themeRegistry;
}

/**
 * Container-autowired, matching themeSubControllerTestThemeRegistry()'s
 * own style -- the "invalid theme" branch this file covers never
 * reaches Renderer::render(), so a fully-wired instance isn't needed
 * for correctness, only for the constructor's own type.
 */
function themeSubControllerTestCurrentTemplate(): CurrentTemplate
{
    $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
    if (! $currentTemplate instanceof CurrentTemplate) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
    }

    return $currentTemplate;
}

function themeSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? themeSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when the requested theme is not among the scanned themes', function (): void {
    $root = themeSubControllerTestRoot();
    $_GET['theme'] = 'my_theme';
    $emptyThemesDir = $root . 'empty-themes/';
    mkdir($emptyThemesDir, 0o777, true);
    $currentConfig = CurrentConfigTestFactory::get();
    $originalThemesDir = $currentConfig->themesDir;
    $currentConfig->themesDir = rtrim($emptyThemesDir, '/');

    try {
        $subController = new ThemeSubController(
            LangTestFactory::get(),
            UrlServiceTestFactory::build(),
            HtmlServiceTestFactory::build(),
            $currentConfig,
            new InputValidator(),
            Paths::fromRoot($root),
            CurrentUserTestFactory::get(),
            new EventDispatcher(),
            themeSubControllerTestEntityManager(),
            themeSubControllerTestThemeRegistry(),
            themeSubControllerTestCurrentTemplate(),
            new Renderer(themeSubControllerTestCurrentTemplate()),
        );

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())
            ->toContain('Invalid theme');
    } finally {
        unset($_GET['theme']);
        $currentConfig->themesDir = $originalThemesDir;
        Kernel::reset();
        themeSubControllerTestRrmdir($root);
    }
});
