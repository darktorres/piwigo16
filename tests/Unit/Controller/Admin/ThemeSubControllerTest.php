<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\ThemeSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\ThemeSubController -- 8 constructor deps, no
 * template rendering at all (dynamically includes another file on the
 * happy path instead). No dedicated Integration/Browser spec of its own.
 *
 * Covers the "invalid theme" fatalError() guard: a well-formed ?theme=
 * value (passes ThemeIdRequest/InputValidator's own charset check
 * cleanly) against a themes directory pointed at an empty temp dir (so
 * ExtensionScanner::scan() finds zero real themes) reaches it directly.
 * The "missing file" fatalError() branch and the real include_once happy
 * path both need a real theme directory with a marker `themeconf.inc.php`
 * on disk, not attempted here.
 */
function themeSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-theme-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    return $root;
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
    $originalThemesDir = $currentConfig->themesDir();
    $currentConfig->setThemesDir(rtrim($emptyThemesDir, '/'));

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
        );

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())->toContain('Invalid theme');
    } finally {
        unset($_GET['theme']);
        $currentConfig->setThemesDir($originalThemesDir);
        Kernel::reset();
        themeSubControllerTestRrmdir($root);
    }
});
