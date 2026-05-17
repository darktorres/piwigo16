<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;
use Piwigo\Theme\TemplateResolver;
use Piwigo\Theme\ThemeRegistry;
use Piwigo\Theme\ThemeRepository;
use Psr\Log\NullLogger;

/**
 * Verifies TemplateResolver's parent-chain walk over the
 * `ChildTheme → ValidTheme` fixture pair.
 *
 *  - child-only.latte exists only under ChildTheme → resolves to child.
 *  - index.latte exists only under ValidTheme → resolves to parent.
 *  - head.latte is declared as `localHead` by ValidTheme but not by
 *    ChildTheme → child inherits the parent's localHead.
 *  - assetDir falls through: child declares `img`, ValidTheme declares
 *    `icon` — child gets first dibs for kinds it covers, parent fills
 *    the rest.
 *  - missing.latte exists in neither → resolves to null.
 */
final class TemplateResolverTest extends TestCase
{
    private string $themesDir;

    private ThemeRegistry $registry;

    private TemplateResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->themesDir = dirname(__DIR__, 3) . '/tests/Fixtures/Themes';

        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        $repo = new class () extends ThemeRepository {
            public function __construct()
            {
            }
        };
        $this->registry = new ThemeRegistry(
            $repo,
            new NullLogger(),
            $this->themesDir,
            dirname(__DIR__, 3) . '/docs/schemas/theme.schema.json',
        );
        $this->resolver = new TemplateResolver($this->registry, $this->themesDir);
    }

    public function testResolveReturnsChildOverrideWhenFileExistsOnChild(): void
    {
        $path = $this->resolver->resolve('ChildTheme', 'child-only.latte');
        self::assertNotNull($path);
        self::assertSame($this->themesDir . '/ChildTheme/child-only.latte', $path);
    }

    public function testResolveFallsThroughToParentWhenChildLacksFile(): void
    {
        $path = $this->resolver->resolve('ChildTheme', 'index.latte');
        self::assertNotNull($path);
        self::assertSame($this->themesDir . '/ValidTheme/index.latte', $path);
    }

    public function testResolveReturnsNullForUnknownFile(): void
    {
        self::assertNull($this->resolver->resolve('ChildTheme', 'no-such-file.latte'));
    }

    public function testResolveReturnsNullForUnknownTheme(): void
    {
        self::assertNull($this->resolver->resolve('NotATheme', 'index.latte'));
    }

    public function testAssetDirChildOverridesParentForSameKind(): void
    {
        $dir = $this->resolver->assetDir('ChildTheme', 'img');
        self::assertSame($this->themesDir . '/ChildTheme/child-img', $dir);
    }

    public function testAssetDirFallsThroughToParentForChildOnlyMissingKind(): void
    {
        $dir = $this->resolver->assetDir('ChildTheme', 'icon');
        self::assertSame($this->themesDir . '/ValidTheme/icon', $dir);
    }

    public function testAssetDirReturnsNullForUnknownKind(): void
    {
        self::assertNull($this->resolver->assetDir('ChildTheme', 'mimeIcon'));
    }

    public function testLocalHeadFallsThroughToParent(): void
    {
        // ChildTheme declares localHead = null (no field) → parent's head.latte.
        $head = $this->resolver->localHead('ChildTheme');
        self::assertSame($this->themesDir . '/ValidTheme/head.latte', $head);
    }
}
