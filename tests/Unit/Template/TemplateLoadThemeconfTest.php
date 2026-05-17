<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Piwigo\Template\Template;

/**
 * Verifies Template::loadThemeconf projects a theme.json manifest into
 * the legacy flat themeconf shape that setTheme() + 19 Latte sites +
 * controllers + SrcImage continue to read.
 *
 * The fixture at `tests/Fixtures/Themes/BundledFixture/` covers every
 * field a bundled theme can declare. Verifies the legacy key mapping:
 *
 *   theme.json key    →  legacy themeconf key
 *   ------------------    -------------------
 *   parent            →  parent
 *   loadParentCss     →  load_parent_css
 *   localHead         →  local_head
 *   colorscheme       →  colorscheme
 *   useStandardPages  →  use_standard_pages
 *   assets.icon       →  icon_dir
 *   assets.img        →  img_dir
 *   assets.mimeIcon   →  mime_icon_dir
 *   assets.adminIcon  →  admin_icon_dir
 *
 * `id` is intentionally NOT emitted — Template::setTheme rewrites it
 * to the directory basename on line 165 regardless.
 */
final class TemplateLoadThemeconfTest extends TestCase
{
    private Template $template;

    #[\Override]
    protected function setUp(): void
    {
        // Skip constructor — it requires a live Lang/Config/etc. None of
        // that touches loadThemeconf's code path.
        $this->template = new \ReflectionClass(Template::class)->newInstanceWithoutConstructor();
    }

    public function testReadsThemeJsonAndProjectsToLegacyShape(): void
    {
        $dir = dirname(__DIR__, 3) . '/tests/Fixtures/Themes/BundledFixture';
        $themeconf = $this->template->loadThemeconf($dir);

        self::assertSame('BundledFixture', $themeconf['name'] ?? null);
        self::assertSame('_base', $themeconf['parent'] ?? null);
        self::assertTrue($themeconf['load_parent_css'] ?? false);
        self::assertSame('head.latte', $themeconf['local_head'] ?? null);
        self::assertSame('dark', $themeconf['colorscheme'] ?? null);
        self::assertFalse($themeconf['use_standard_pages'] ?? null);

        self::assertSame('icon', $themeconf['icon_dir'] ?? null);
        self::assertSame('images', $themeconf['img_dir'] ?? null);
        self::assertSame('icon/mimetypes', $themeconf['mime_icon_dir'] ?? null);
        self::assertSame('admin/icon', $themeconf['admin_icon_dir'] ?? null);

        self::assertArrayNotHasKey('id', $themeconf, 'id must come from setTheme(), not loadThemeconf()');
    }

    public function testParentOnlyManifestYieldsParentKey(): void
    {
        // OrphanParent fixture has theme.json with parent: does_not_exist
        // — verifies the parent field comes through the JSON projection
        // even when the rest of the manifest is minimal.
        $themeconf = $this->template->loadThemeconf(dirname(__DIR__, 3) . '/tests/Fixtures/Themes/OrphanParent');
        self::assertSame('does_not_exist', $themeconf['parent'] ?? null);
    }

    public function testEmptyDirYieldsEmptyArray(): void
    {
        $themeconf = $this->template->loadThemeconf(sys_get_temp_dir());
        self::assertSame([], $themeconf);
    }
}
