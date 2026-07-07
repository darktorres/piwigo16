<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Console-clean smoke: no server-error markers or JS errors on standard
 * entry-point routes. Covers anonymous gallery routes and authenticated
 * admin routes.
 */

$anonRoutes = [
    'gallery home'    => '/index.php',
    'identification'  => '/identification.php',
    'search'          => '/search.php',
    'tags'            => '/tags.php',
];

foreach ($anonRoutes as $name => $path) {
    it("{$name} — clean (no server errors, no JS errors)", function () use ($path): void {
        $page = H::gotoOk($this, $path);
        $page->assertNoJavaScriptErrors();
    });
}

$adminRoutes = [
    'admin dashboard'  => '/admin.php',
    'admin albums'     => '/admin.php?page=albums',
    'admin batch_manager (filter=all)' => '/admin.php?page=batch_manager&filter=all',
    'admin photos_add direct' => '/admin.php?page=photos_add&section=direct',
    'admin configuration' => '/admin.php?page=configuration',
    'admin history'    => '/admin.php?page=history',
    'admin plugins'    => '/admin.php?page=plugins',
    'admin languages'  => '/admin.php?page=languages',
    'admin themes'     => '/admin.php?page=themes',
    'admin maintenance' => '/admin.php?page=maintenance',
];

foreach ($adminRoutes as $name => $path) {
    it("{$name} — clean (no server errors, no JS errors)", function () use ($path): void {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, $path);
        $page->assertNoJavaScriptErrors();
    });
}
