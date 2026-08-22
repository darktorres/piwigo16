<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Listener\HtmlRenderingListener;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Tag\TagService;
use ReflectionMethod;
use RuntimeException;

/**
 * Piwigo\Search\SearchFilterRenderer::renderTagsFound() (private) --
 * exercised directly via Reflection with a hand-built `$page` array
 * rather than driving a full allwords search end-to-end: reaching this
 * method through a real search requires searchAllwords()'s own
 * 'tags'-active-search-field + tag-name-LIKE-match conditions, which
 * isn't this test's own subject (the escaping fix in the method itself
 * is) -- same "test the right unit at the right level" reasoning as
 * this project's other Reflection-based tests (e.g. AdminShellTest.php's
 * buildChangeThemeUrl() coverage, P44-C).
 */
final class SearchFilterRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->conn = DbConnection::build();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function containerGet(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    public function testRenderTagsFoundEscapesAnHtmlSpecialCharacterBearingTagName(): void
    {
        // Real per-request bootstrap (RequestBootstrap::connect()) always
        // registers this listener, which is what makes RenderTagUrl's
        // 'url_name' a safe, slugified value (StringHelper::str2url(),
        // no HTML-special characters at all) -- this bare Integration
        // test skips that bootstrap entirely, so it must register the
        // listener itself or url_name would default to the raw
        // (post-strip_tags) name instead, which is not what a real
        // request ever actually serves.
        $this->containerGet(EventDispatcher::class)->registerSubscriber(
            new HtmlRenderingListener($this->containerGet(HtmlService::class), $this->containerGet(CurrentConfig::class))
        );

        // TagService::createTag()'s own strip_tags() blocks a literal
        // <script>-shaped tag name at creation -- this is the real,
        // still-open gap: strip_tags() never touches '&'/'"'.
        $tagName = 'Wild & "Nature"';
        $tagService = $this->containerGet(TagService::class);
        $outcome = $tagService->createTag($tagName);
        if ($outcome->id === null) {
            throw new RuntimeException('createTag() did not return an id: ' . var_export($outcome->error, true));
        }
        $tagId = $outcome->id;

        // getAvailableTags() only returns tags with at least one real
        // image attached (TagRepository::countImagesPerTag() INNER JOINs
        // image_tag) -- an orphan tag never reaches this code at all.
        // image_id 1 is a real fixture photo (confirmed by
        // AdminDispatcherPageMapTest.php's own fixture use elsewhere).
        $this->conn->executeStatement(
            'INSERT INTO image_tag (image_id, tag_id) VALUES (1, ?)',
            [$tagId]
        );

        try {
            $renderer = $this->containerGet(SearchFilterRenderer::class);
            $method = new ReflectionMethod(SearchFilterRenderer::class, 'renderTagsFound');

            $result = $method->invoke($renderer, [
                'search_details' => [
                    'matching_tag_ids' => [$tagId],
                ],
            ]);

            self::assertIsArray($result);
            self::assertCount(1, $result);
            $tagLink = $result[0];
            self::assertIsString($tagLink);
            self::assertStringNotContainsString('Wild & "Nature"</a>', $tagLink);
            self::assertStringContainsString('Wild &amp; &quot;Nature&quot;</a>', $tagLink);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
            $this->conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
        }
    }
}
