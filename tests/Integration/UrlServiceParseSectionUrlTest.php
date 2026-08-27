<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\HttpStatusLine;
use Piwigo\Core\Kernel;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\UrlService;
use RuntimeException;

/**
 * Records the message passed to whichever of badRequest()/pageNotFound()/
 * fatalError() fired, then throws -- avoids needing a real Kernel::boot()/
 * Template/Lang stack (RedirectServiceTest's own docblock) or the
 * 'check_for_updates' lock-winning dance PageTailTest needs to sidestep
 * Admin\Extensions\CoreUpdateService, since parseSectionUrl()'s real
 * CategoryService/TagService lookups (the actual thing under test here)
 * are constructed internally from a fresh DbConnection::build(), entirely
 * independent of this injected fake.
 */
final class UrlServiceParseSectionUrlTestHtmlRenderer implements HtmlRenderingInterface
{
    public ?string $lastMessage = null;

    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[Override]
    public function getCatDisplayNameCache(string $uppercats, ?string $url = '', bool $singleLink = false, ?string $linkClass = null, ?string $authKey = null): string
    {
        return '';
    }

    #[Override]
    public function getCatBreadcrumb(string $uppercats): array
    {
        return [];
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        return 0;
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        return 0;
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('accessDenied');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        $this->lastMessage = $msg;

        throw new RuntimeException('badRequest: ' . $msg);
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        $this->lastMessage = $msg;

        throw new RuntimeException('pageNotFound: ' . ($msg ?? ''));
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->lastMessage = $msg;

        throw new RuntimeException('fatalError: ' . $msg);
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '';
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        return '';
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
    {
        return new HttpStatusLine($code, $text);
    }

    #[Override]
    public function renderElementName(array $info): string
    {
        return '';
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        return '';
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        return '';
    }
}

final class UrlServiceParseSectionUrlTestRedirectService implements RedirectServiceInterface
{
    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new RuntimeException('unexpected redirectHttp() call');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new RuntimeException('unexpected redirectHtml() call');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new RuntimeException('unexpected redirect() call');
    }
}

/**
 * Piwigo\Url\UrlService::parseSectionUrl()'s 'categories'/'tags' branches
 * -- both construct a real CategoryService/TagService internally
 * (DbConnection::build()), so they need a real fixture DB. Fixture
 * category 1 ('Sample Album') is the root, category 2 ('Nested Sub
 * Album') its child; fixture tags 1/2/3 are 'nature'/'travel'/'family'.
 *
 * The 'categories' loop's own `$loop_counter++ > count($tokens) + 10`
 * `fatalError('infinite loop?')` guard is NOT chased here: every one of
 * the loop's 3 outcomes per iteration either advances $nextToken by at
 * least 1 (a numeric-id match, or a resolved permalink) or calls
 * fatalError()/pageNotFound() itself (both `: never` on the real
 * HtmlRenderingInterface -- confirmed via this file's own
 * UrlServiceParseSectionUrlTestHtmlRenderer, which throws instead of
 * exiting but still never returns), or `break`s the loop outright on a
 * recognised boundary token. There is no branch that leaves $nextToken
 * unchanged and lets the loop continue, so with a finite $tokens array
 * the loop can run at most count($tokens) times -- never enough to trip a
 * count($tokens)+10 threshold. Forcing it open would need a
 * HtmlRenderingInterface double that (unlike every real implementation)
 * doesn't actually halt on pageNotFound(), which wouldn't reflect any
 * reachable real behavior.
 */
final class UrlServiceParseSectionUrlTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id IN (1, 2)');
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    private function service(): UrlService
    {
        return UrlServiceTestFactory::build(new UrlServiceParseSectionUrlTestHtmlRenderer());
    }

    private function redirect(): UrlServiceParseSectionUrlTestRedirectService
    {
        return new UrlServiceParseSectionUrlTestRedirectService();
    }

    public function testParseSectionUrlResolvesACategoryByPlainNumericId(): void
    {
        $i = 0;
        $page = $this->service()
            ->parseSectionUrl(['category', '1'], $i, $this->redirect());

        self::assertSame(Section::Categories, $page['section']);
        self::assertInstanceOf(CategoryInfo::class, $page['category']);
        self::assertSame(1, $page['category']->id);
        self::assertSame(2, $i);
    }

    public function testParseSectionUrlPageNotFoundsForANonexistentCategoryId(): void
    {
        $htmlRenderer = new UrlServiceParseSectionUrlTestHtmlRenderer();
        $service = UrlServiceTestFactory::build($htmlRenderer);
        $i = 0;

        try {
            $service->parseSectionUrl(['category', '999999'], $i, $this->redirect());
            self::fail('Expected a RuntimeException from pageNotFound()');
        } catch (RuntimeException) {
            self::assertSame('Requested album does not exist', $htmlRenderer->lastMessage);
        }
    }

    public function testParseSectionUrlResolvesCombinedCategories(): void
    {
        $i = 0;
        // 2 consecutive numeric tokens under 'category/' -- the 1st is the
        // primary category, the 2nd a combined one.
        $page = $this->service()
            ->parseSectionUrl(['category', '1', '2'], $i, $this->redirect());

        self::assertInstanceOf(CategoryInfo::class, $page['category']);
        self::assertSame(1, $page['category']->id);
        self::assertIsArray($page['combined_categories']);
        self::assertCount(1, $page['combined_categories']);
        $combined0 = $page['combined_categories'][0];
        self::assertInstanceOf(CategoryInfo::class, $combined0);
        self::assertSame(2, $combined0->id);
        self::assertSame(3, $i);
    }

    public function testParseSectionUrlPageNotFoundsForANonexistentCombinedCategory(): void
    {
        $htmlRenderer = new UrlServiceParseSectionUrlTestHtmlRenderer();
        $service = UrlServiceTestFactory::build($htmlRenderer);
        $i = 0;

        try {
            $service->parseSectionUrl(['category', '1', '999999'], $i, $this->redirect());
            self::fail('Expected a RuntimeException from pageNotFound()');
        } catch (RuntimeException) {
            self::assertSame('Requested album does not exist', $htmlRenderer->lastMessage);
        }
    }

    public function testParseSectionUrlResolvesACategoryByItsPermalink(): void
    {
        $this->conn->executeStatement("UPDATE categories SET permalink = 'sub-album' WHERE id = 2");

        $i = 0;
        $page = $this->service()
            ->parseSectionUrl(['category', 'sub-album'], $i, $this->redirect());

        self::assertInstanceOf(CategoryInfo::class, $page['category']);
        self::assertSame(2, $page['category']->id);
        self::assertSame([
            'cat_permalink' => 'sub-album',
        ], $page['hit_by']);
        self::assertSame(2, $i);
    }

    public function testParseSectionUrlResolvesAMultiSegmentPermalink(): void
    {
        // A permalink value can itself contain a literal '/' (a
        // "path-style" permalink, independent of the real category
        // hierarchy) -- the sibling test above only ever builds a
        // single-token $maybe_permalinks entry, never exercising the
        // progressive-join accumulation (`$maybe_permalinks[] =
        // $maybe_permalinks[count-1] . '/' . $tokens[$current_token]`)
        // that a 2nd token needs.
        $this->conn->executeStatement("UPDATE categories SET permalink = 'parent-word/child-word' WHERE id = 2");

        $i = 0;
        $page = $this->service()
            ->parseSectionUrl(['category', 'parent-word', 'child-word'], $i, $this->redirect());

        self::assertInstanceOf(CategoryInfo::class, $page['category']);
        self::assertSame(2, $page['category']->id);
        self::assertSame([
            'cat_permalink' => 'parent-word/child-word',
        ], $page['hit_by']);
        // Both tokens consumed -- the match was found at the longest
        // (2-token) combination, not a shorter prefix.
        self::assertSame(3, $i);
    }

    public function testParseSectionUrlResolvesASecondCombinedCategoryViaPermalink(): void
    {
        $this->conn->executeStatement("UPDATE categories SET permalink = 'sub-album' WHERE id = 2");

        $i = 0;
        // Primary category resolved by plain numeric id (category 1); the
        // 2nd token resolves via permalink instead of a numeric id --
        // exercises the permalink branch's own "$category !== null" ->
        // combined_category_ids[] arm (test_parse_section_url_resolves_combined_categories()
        // above only exercises that arm via 2 numeric ids).
        $page = $this->service()
            ->parseSectionUrl(['category', '1', 'sub-album'], $i, $this->redirect());

        self::assertInstanceOf(CategoryInfo::class, $page['category']);
        self::assertSame(1, $page['category']->id);
        self::assertIsArray($page['combined_categories']);
        self::assertCount(1, $page['combined_categories']);
        $combined0 = $page['combined_categories'][0];
        self::assertInstanceOf(CategoryInfo::class, $combined0);
        self::assertSame(2, $combined0->id);
        // Only the primary category's own permalink hit is ever recorded
        // into hit_by (see the "$category === null" branch just above
        // this one in the source) -- a combined category resolved via
        // permalink leaves hit_by empty.
        self::assertArrayNotHasKey('hit_by', $page);
        self::assertSame(3, $i);
    }

    public function testParseSectionUrlPageNotFoundsForAnUnresolvablePermalink(): void
    {
        $htmlRenderer = new UrlServiceParseSectionUrlTestHtmlRenderer();
        $service = UrlServiceTestFactory::build($htmlRenderer);
        $i = 0;

        try {
            $service->parseSectionUrl(['category', 'no-such-permalink-anywhere'], $i, $this->redirect());
            self::fail('Expected a RuntimeException from pageNotFound()');
        } catch (RuntimeException) {
            self::assertSame('Permalink for album not found', $htmlRenderer->lastMessage);
        }
    }

    public function testParseSectionUrlResolvesTagsByNumericId(): void
    {
        $i = 0;
        $page = $this->service()
            ->parseSectionUrl(['tags', '1'], $i, $this->redirect());

        self::assertSame(Section::Tags, $page['section']);
        self::assertIsArray($page['tags']);
        self::assertCount(1, $page['tags']);
        $tag0 = $page['tags'][0];
        self::assertIsArray($tag0);
        self::assertSame(1, $tag0['id']);
        self::assertSame(2, $i);
    }

    public function testParseSectionUrlResolvesTagsByUrlName(): void
    {
        // A non-numeric token (or CurrentConfig::tagUrlStyle() === 'tag',
        // forcing even a numeric-looking token down this path) is
        // collected into $requested_tag_url_names instead of
        // $requested_tag_ids -- the sibling test above only ever exercises
        // the numeric-id arm.
        $i = 0;
        $page = $this->service()
            ->parseSectionUrl(['tags', 'nature'], $i, $this->redirect());

        self::assertSame(Section::Tags, $page['section']);
        self::assertIsArray($page['tags']);
        self::assertCount(1, $page['tags']);
        $tag0 = $page['tags'][0];
        self::assertIsArray($tag0);
        self::assertSame(1, $tag0['id']);
        self::assertSame(2, $i);
    }

    public function testParseSectionUrlPageNotFoundsWhenNoTagMatches(): void
    {
        $htmlRenderer = new UrlServiceParseSectionUrlTestHtmlRenderer();
        $service = UrlServiceTestFactory::build($htmlRenderer);
        $i = 0;

        try {
            $service->parseSectionUrl(['tags', '999999'], $i, $this->redirect());
            self::fail('Expected a RuntimeException from pageNotFound()');
        } catch (RuntimeException) {
            self::assertSame('Requested tag does not exist', $htmlRenderer->lastMessage);
        }
    }

    public function testParseSectionUrlRejectsATagsTokenWithNoIdsOrNames(): void
    {
        $htmlRenderer = new UrlServiceParseSectionUrlTestHtmlRenderer();
        $service = UrlServiceTestFactory::build($htmlRenderer);
        $i = 0;

        try {
            // Immediately followed by a 'start-' boundary token -- the tag
            // token-scan loop breaks before collecting any id/name at all.
            $service->parseSectionUrl(['tags', 'start-0'], $i, $this->redirect());
            self::fail('Expected a RuntimeException from badRequest()');
        } catch (RuntimeException) {
            self::assertSame('at least one tag required', $htmlRenderer->lastMessage);
        }
    }
}
