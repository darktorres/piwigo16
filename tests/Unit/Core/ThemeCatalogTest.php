<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeCatalog;

/**
 * Piwigo\Core\ThemeCatalog -- theme catalog helpers. No dedicated
 * Integration/Browser spec of its own.
 *
 * Only `checkThemeInstalled()` is covered here: a pure filesystem check
 * against a real temp root, no DB access at all. `getPwgThemes()` needs
 * a real DB read of every `piwigo_themes` row combined with a real
 * filesystem check per row, disproportionate for this pass and not
 * attempted here.
 */
function themeCatalogTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-theme-catalog-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

function themeCatalogTestRrmdir(string $dir): void
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
        is_dir($path) ? themeCatalogTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('checkThemeInstalled returns true when the theme directory has a real themeconf.inc.php', function (): void {
    $root = themeCatalogTestRoot();

    try {
        $currentConfig = new CurrentConfig();
        $currentConfig->setThemesDir('themes');
        mkdir($root . 'themes/my_theme', 0o777, true);
        file_put_contents($root . 'themes/my_theme/themeconf.inc.php', '<?php');

        $result = ThemeCatalog::checkThemeInstalled('my_theme', Paths::fromRoot($root), $currentConfig);

        expect($result)
            ->toBeTrue();
    } finally {
        themeCatalogTestRrmdir($root);
    }
});

test('checkThemeInstalled returns false when the theme directory has no themeconf.inc.php', function (): void {
    $root = themeCatalogTestRoot();

    try {
        $currentConfig = new CurrentConfig();
        $currentConfig->setThemesDir('themes');
        mkdir($root . 'themes/empty_theme', 0o777, true);

        $result = ThemeCatalog::checkThemeInstalled('empty_theme', Paths::fromRoot($root), $currentConfig);

        expect($result)
            ->toBeFalse();
    } finally {
        themeCatalogTestRrmdir($root);
    }
});

test('checkThemeInstalled returns false for a theme id with no real directory at all', function (): void {
    $root = themeCatalogTestRoot();

    try {
        $currentConfig = new CurrentConfig();
        $currentConfig->setThemesDir('themes');
        mkdir($root . 'themes', 0o777, true);

        $result = ThemeCatalog::checkThemeInstalled('not_a_real_theme', Paths::fromRoot($root), $currentConfig);

        expect($result)
            ->toBeFalse();
    } finally {
        themeCatalogTestRrmdir($root);
    }
});
