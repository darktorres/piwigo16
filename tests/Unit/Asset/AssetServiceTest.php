<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Piwigo\Asset\AssetService;
use Psr\Log\NullLogger;

/**
 * Exercises AssetService against `tests/Fixtures/Plugins/AssetPlugin/`
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
        $this->pluginsDir = dirname(__DIR__, 3) . '/tests/Fixtures/Plugins';
    }

    public function testRegisterAndRenderEmitsCssThenScriptForSingleEntry(): void
    {
        $service = $this->makeService();
        $service->registerEntry('AssetPlugin/admin');

        $tags = $service->renderHeadTags();
        self::assertSame([
            '<link rel="stylesheet" href="plugins/AssetPlugin/dist/assets/admin-style-def456.css">',
            '<script type="module" src="plugins/AssetPlugin/dist/assets/admin-abc123.js"></script>',
        ], $tags);
    }

    public function testJsOnlyEntryEmitsOnlyScript(): void
    {
        $service = $this->makeService();
        $service->registerEntry('AssetPlugin/public');

        $tags = $service->renderHeadTags();
        self::assertSame([
            '<script type="module" src="plugins/AssetPlugin/dist/assets/public-789xyz.js"></script>',
        ], $tags);
    }

    public function testMultipleCssBundlesAllEmit(): void
    {
        $service = $this->makeService();
        $service->registerEntry('AssetPlugin/extras');

        $tags = $service->renderHeadTags();
        self::assertCount(3, $tags);
        self::assertSame(
            '<link rel="stylesheet" href="plugins/AssetPlugin/dist/assets/extras-jkl012.css">',
            $tags[0],
        );
        self::assertSame(
            '<link rel="stylesheet" href="plugins/AssetPlugin/dist/assets/extras-mno345.css">',
            $tags[1],
        );
        self::assertSame(
            '<script type="module" src="plugins/AssetPlugin/dist/assets/extras-ghi789.js"></script>',
            $tags[2],
        );
    }

    public function testDuplicateRegistrationFoldsSilently(): void
    {
        $service = $this->makeService();
        $service->registerEntry('AssetPlugin/admin');
        $service->registerEntry('AssetPlugin/admin');

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
        $service->registerEntry('AssetPlugin/no_such_entry');

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
        $service->registerEntry('AssetPlugin/public');
        $service->registerEntry('AssetPlugin/admin');

        $tags = $service->renderHeadTags();
        self::assertSame(
            '<script type="module" src="plugins/AssetPlugin/dist/assets/public-789xyz.js"></script>',
            $tags[0],
            'public registered first — its script should lead',
        );
    }
}
