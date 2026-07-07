<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('gallery title setting round-trips through the $conf write path', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=main');
    $page->assertPresent('input[name="gallery_title"]');

    $originalTitle = $page->value('input[name="gallery_title"]');
    if ($originalTitle === '') {
        $originalTitle = 'Piwigo';
    }
    $newTitle = 'Browser Test Gallery Title ' . uniqid();

    $page = $page
        ->fill('gallery_title', $newTitle)
        ->click('submit');

    // Reload and verify the new value persisted.
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=main');
    expect($page->value('input[name="gallery_title"]'))->toBe($newTitle);

    // Restore original.
    $page->fill('gallery_title', $originalTitle)->click('submit');
});
