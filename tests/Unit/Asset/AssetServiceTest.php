<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Piwigo\Asset\AssetService;
use Psr\Log\NullLogger;

/**
 * Exercises AssetService against `tests/fixtures/plugins/asset_plugin/`
 * — a fixture tree that ships a Vite-format `dist/manifest.json` with
 * three entries (one CSS-bearing admin entry, one JS-only public
 * entry, one entry with multiple CSS bundles).
 *
 * Verifies:
 *  - registerEntry + renderHeadTags emit the expected <script> / <link>
 *    tags in CSS-first order
 *  - Duplicate registerEntry calls are folded
 *  - Unknown plugin id yields no tags (no fatal)
 *  - Unknown entry inside a known plugin yields no tags (no fatal)
 *  - Malformed entry reference is ignored
 */
/** @psalm-suppress PropertyNotSetInConstructor — initialized in setUp() */
final class AssetServiceTest extends TestCase
{
    private string $pluginsDir;

    private function makeService(): AssetService
    {
        return new AssetService($this->pluginsDir, new NullLogger());
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->pluginsDir = PHPWG_ROOT_PATH . 'tests/fixtures/plugins';
    }

    public function testRegisterAndRenderEmitsCssThenScriptForSingleEntry(): void
    {
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/admin');

        $tags = $service->renderHeadTags();
        self::assertSame([
            '<link rel="stylesheet" href="plugins/asset_plugin/dist/assets/admin-style-def456.css">',
            '<script type="module" src="plugins/asset_plugin/dist/assets/admin-abc123.js"></script>',
        ], $tags);
    }

    public function testJsOnlyEntryEmitsOnlyScript(): void
    {
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/public');

        $tags = $service->renderHeadTags();
        self::assertSame([
            '<script type="module" src="plugins/asset_plugin/dist/assets/public-789xyz.js"></script>',
        ], $tags);
    }

    public function testMultipleCssBundlesAllEmit(): void
    {
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/extras');

        $tags = $service->renderHeadTags();
        self::assertCount(3, $tags);
        self::assertSame(
            '<link rel="stylesheet" href="plugins/asset_plugin/dist/assets/extras-jkl012.css">',
            $tags[0],
        );
        self::assertSame(
            '<link rel="stylesheet" href="plugins/asset_plugin/dist/assets/extras-mno345.css">',
            $tags[1],
        );
        self::assertSame(
            '<script type="module" src="plugins/asset_plugin/dist/assets/extras-ghi789.js"></script>',
            $tags[2],
        );
    }

    public function testDuplicateRegistrationFoldsSilently(): void
    {
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/admin');
        $service->registerEntry('asset_plugin/admin');

        // Two registrations → still one set of tags.
        self::assertCount(2, $service->renderHeadTags());
    }

    public function testUnknownPluginYieldsNoTags(): void
    {
        $service = $this->makeService();
        $service->registerEntry('does_not_exist/admin');

        self::assertSame([], $service->renderHeadTags());
    }

    public function testUnknownEntryInKnownPluginYieldsNoTags(): void
    {
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/no_such_entry');

        self::assertSame([], $service->renderHeadTags());
    }

    public function testMalformedEntryReferenceIsIgnored(): void
    {
        $service = $this->makeService();
        $service->registerEntry('no_slash_here');
        $service->registerEntry('/leading');
        $service->registerEntry('trailing/');
        $service->registerEntry('');

        self::assertSame([], $service->renderHeadTags());
    }

    public function testTagsForMultiplePluginsRenderInRegistrationOrder(): void
    {
        // Re-register two distinct entries to verify cross-plugin ordering.
        $service = $this->makeService();
        $service->registerEntry('asset_plugin/public');
        $service->registerEntry('asset_plugin/admin');

        $tags = $service->renderHeadTags();
        self::assertSame(
            '<script type="module" src="plugins/asset_plugin/dist/assets/public-789xyz.js"></script>',
            $tags[0],
            'public registered first — its script should lead',
        );
    }
}
