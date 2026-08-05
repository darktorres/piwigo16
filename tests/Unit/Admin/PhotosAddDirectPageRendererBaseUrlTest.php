<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\PhotosAddDirectPageRenderer;

/**
 * PhotosAddDirectPageRenderer::baseUrl() -- CoreTabsTest's own
 * `coreTabsUrlService()` real UrlService returns an empty getRootUrl() in
 * the CLI test context (no live request), which makes a non-empty root
 * indistinguishable from a dropped/reordered concatenation. This uses a
 * fake with a real non-empty root to actually observe it.
 */
final class PhotosAddDirectPageRendererBaseUrlTest extends TestCase
{
    public function test_base_url_prefixes_the_admin_page_with_the_root_url(): void
    {
        self::assertSame(
            '/piwigo/admin.php?page=photos_add',
            PhotosAddDirectPageRenderer::baseUrl(new PhotosAddDirectPageRendererTestFakeUrlService())
        );
    }
}
