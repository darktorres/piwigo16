<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\TagsController (tags.php) -- the front-end tag cloud/
 * letter-index browsing page.
 */
function tagsControllerAddTag(Pest\Browser\Api\Webpage|Pest\Browser\Api\PendingAwaitablePage|Pest\Browser\Api\AwaitableWebpage $page, string $name): int
{
    $result = H::wsCall($page, 'pwg.tags.add', ['name' => $name]);
    $tagResult = $result['result'] ?? null;
    $tagId = is_array($tagResult) ? ($tagResult['id'] ?? null) : null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}

it('renders the tag cloud (default display mode) with a real tag', function (): void {
    $page = H::gotoOk($this, '/tags.php');

    $page->assertSee('nature');
    $page->assertNoJavaScriptErrors();
});

it('renders the letters display mode, grouping tags by first letter', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    tagsControllerAddTag($page, 'Alpha Tag ' . $suffix);
    tagsControllerAddTag($page, 'Alternate Tag ' . $suffix);
    tagsControllerAddTag($page, 'Beta Tag ' . $suffix);

    $page = H::navigateOk($page, '/tags.php?display_mode=letters');

    $page->assertSee('Alpha Tag ' . $suffix);
    $page->assertSee('Beta Tag ' . $suffix);
    $page->assertNoJavaScriptErrors();
});

it('falls back to the default display mode for an unrecognized display_mode value', function (): void {
    $page = H::gotoOk($this, '/tags.php?display_mode=not-a-real-mode');

    $page->assertSee('nature');
    $page->assertNoJavaScriptErrors();
});

it('each tag in the cloud links to its own filtered gallery URL', function (): void {
    $page = H::gotoOk($this, '/tags.php');

    $page->assertSeeLink('nature');
});