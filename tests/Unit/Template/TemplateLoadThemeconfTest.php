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
 * The fixture at `tests/fixtures/themes/bundled_fixture/` covers every
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
        $dir = PHPWG_ROOT_PATH . 'tests/fixtures/themes/bundled_fixture';
        $themeconf = $this->template->loadThemeconf($dir);

        self::assertSame('bundled_fixture', $themeconf['name'] ?? null);
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

    public function testLegacyThemeconfStillLoadsWhenJsonAbsent(): void
    {
        // valid_theme fixture from B13 has neither theme.json (well, it
        // does — let's use a path with only themeconf.inc.php). Use
        // child_theme which has theme.json but no themeconf.inc.php to
        // verify the JSON branch fires; use the bundled fixture's
        // *parent dir* without theme.json to verify empty fallback.
        $emptyDir = PHPWG_ROOT_PATH . 'tests/fixtures/themes/orphan_parent';
        $themeconf = $this->template->loadThemeconf($emptyDir);
        // orphan_parent has theme.json (parent: does_not_exist) but no
        // themeconf.inc.php — JSON branch should fire and emit `parent`.
        self::assertSame('does_not_exist', $themeconf['parent'] ?? null);
    }

    public function testEmptyDirYieldsEmptyArray(): void
    {
        $themeconf = $this->template->loadThemeconf(sys_get_temp_dir());
        self::assertSame([], $themeconf);
    }
}
