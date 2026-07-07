<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('API search finds photo by name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Test Album ' . uniqid()]);
    $albumId = (int) $album['result']['id'];

    $uniqueName = 'BrowserSearchTarget_' . uniqid();
    $image = H::makeTestImage('Search Target');
    H::uploadPhotoViaApi($image, $albumId, $uniqueName);
    @unlink($image);

    $search = H::wsCall($page, 'pwg.images.search', ['query' => $uniqueName]);
    expect($search['stat'])->toBe('ok');
    $names = array_column($search['result']['images'], 'name');
    expect($names)->toContain($uniqueName);
});

it('gallery search page renders without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=sunset');
    $page->assertNoJavaScriptErrors();
});

it('search with no results renders an empty state without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=zzznomatch99xqz');
    $page->assertNoJavaScriptErrors();
});
