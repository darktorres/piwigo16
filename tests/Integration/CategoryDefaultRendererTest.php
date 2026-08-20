<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\Projection\CategoryDefaultResult;
use Piwigo\Comment\CommentEntity;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Projection\ThumbnailsView;
use Piwigo\Core\Kernel;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\RequestMetricsTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Users\User;

/**
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): 5 real images, ids 1-5,
 * files 'fixture-photo-N.jpg', names 'Photo N', hit=0 for every one,
 * rating_score 4.50/3.00/5.00/2.00/NULL for ids 1-5 respectively.
 *
 * Real service construction throughout, same shape as
 * tests/Integration/NoPhotoYetRendererTest.php/PictureCommentRendererTest.php:
 * a real Template compiling the actual themes/default/template/thumbnails.latte,
 * a real ImageRepository/CommentRepository against the fixture DB.
 */
final class CategoryDefaultRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryDefaultRenderer $renderer;

    private Template $template;

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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // See PictureCommentRendererTest's identical comment: skips
        // Template's own data_dir_checked write, which would otherwise
        // reach for a full RequestBootstrap dependency this test never
        // boots.
        $currentConfig->dataDirChecked = '1';
        // thumbnails.latte reads $derivative_params (assigned from
        // ImageStdParams::getByType() by CategoryDefaultRenderer::render()
        // itself) -- ImageStdParams::$all_type_map starts empty until
        // loadFromDb() populates it. loadFromDb() reaches its own
        // repositories via a fresh EntityManagerFactory::build(DbConnection::
        // build()), independent of ConfigService/CurrentConfig entirely, so
        // it works here whether or not the fixture's derivative_settings/
        // derivative_size rows are populated (falls back to sane built-in
        // sizing if not, same as UploadServiceTest's own identical setup).
        // loadConfFromDb() below is unrelated to ImageStdParams -- it's
        // this test's own way of seeding every other real config-backed
        // display flag CategoryDefaultRenderer/thumbnails.latte reads.
        $configService = new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get());
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->loadFromDb();

        $this->conn = DbConnection::build();
        $em = EntityManagerFactory::build($this->conn);
        $imageRepo = $em->getRepository(ImageEntity::class);
        $commentRepo = $em->getRepository(CommentEntity::class);

        $htmlService = HtmlServiceTestFactory::build();
        // thumbnails.latte's own {var $derivative =
        // $pwg->derivative(...)} constructs a real DerivativeImage per
        // thumbnail, whose getUrl() resolves UrlServiceInterface live from
        // the container -- $urlService below must share the same
        // container-shared RootPathOverride for setMakeFullUrl()-style
        // state to be visible across both, see that class's own docblock.
        $rootPathOverride = Kernel::container()->get(RootPathOverride::class);
        if (! $rootPathOverride instanceof RootPathOverride) {
            throw new LogicException('Container returned an unexpected type for ' . RootPathOverride::class);
        }
        $urlService = UrlServiceTestFactory::build($htmlService, $rootPathOverride);

        $processCache = Kernel::container()->get(ProcessCache::class);
        if (! $processCache instanceof ProcessCache) {
            throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
        }

        $this->buildTemplate();
        $this->renderer = new CategoryDefaultRenderer($htmlService, $imageRepo, $commentRepo, $urlService, new SessionService($em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), ImageStdParamsTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), $processCache, RequestMetricsTestFactory::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        // Restore the fixture's real hit=0 for id=3 in case a test mutated
        // it via setImageHit() below.
        $this->conn->executeStatement('UPDATE images SET hit = 0 WHERE id = 3');
        parent::tearDown();
    }

    private function setImageHit(int $imageId, int $hit): void
    {
        $this->conn->executeStatement(
            'UPDATE images SET hit = :hit WHERE id = :id',
            [
                'hit' => $hit,
                'id' => $imageId,
            ]
        );
    }

    private function buildTemplate(): Template
    {
        // root/theme='default' points the template-dir chain at the real
        // themes/default/template/ directory thumbnails.latte lives in,
        // same real-root shape every real Template() construction site uses.
        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');
        CurrentTemplateTestFactory::get()->set($this->template);

        return $this->template;
    }

    private function seedUser(bool $showNbHits, bool $showNbComments): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 3,
            'username' => 'fixture_regular_user',
            'status' => 'normal',
            'show_nb_hits' => $showNbHits,
            'show_nb_comments' => $showNbComments,
        ]));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function thumbnailAt(CategoryDefaultResult $result, int $index): array
    {
        $thumbnail = $result->thumbnails[$index];
        if (! is_array($thumbnail)) {
            throw new LogicException('unreachable -- every CategoryDefaultResult::$thumbnails entry is a shaped array');
        }

        return $thumbnail;
    }

    // CategoryDefaultRenderer::render() only returns raw thumbnail-grid
    // data now (Piwigo\Category\* is L2aCoreDomain and may not depend on
    // Renderer/View directly) -- this mirrors GalleryController's own
    // real ThumbnailsView construction, to verify the actual Latte
    // rendering (translate_dec pluralization, etc.), not just the PHP
    // -side data shaping the array-based assertions below already cover.
    private function renderedThumbnailsHtml(CategoryDefaultResult $result): string
    {
        $html = new Renderer(CurrentTemplateTestFactory::get())->render(new ThumbnailsView(
            derivativeParams: $result->derivativeParams,
            maxRequests: $result->maxRequests,
            showThumbnailCaption: $result->showThumbnailCaption,
            thumbnails: $result->thumbnails,
            rootUrl: '',
            iconDir: '',
        ));

        return (string) $html;
    }

    public function testRenderOrdersThumbnailsByRankNotByTheIdsOwnNumericOrder(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // Deliberately out of numeric order: rank 0 => id 3, rank 1 => id 1,
        // rank 2 => id 2 -- a real transposition bug (e.g. sorting by id
        // instead of by rank) would produce a different order here.
        $result = $this->renderer->render([3, 1, 2], 0, 3, Section::Categories);

        self::assertSame([3, 1, 2], array_column($result->thumbnails, 'id'));
    }

    public function testRenderReturnsTheSlideshowUrlForTheFirstRankedPicture(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);
        $urlService = UrlServiceTestFactory::build();

        $result = $this->renderer->render([3, 1, 2], 0, 3, Section::Categories);

        // The first-ranked picture after sorting is id=3 (rank 0) --
        // duplicatePictureUrl()/addUrlParams() are the same real UrlService
        // methods/state CategoryDefaultRenderer itself calls internally, so
        // this proves the renderer threads through the *correct* picture
        // (id 3, file fixture-photo-3.jpg), not a mixed-up start/first item.
        $expected = $urlService->addUrlParams(
            $urlService->duplicatePictureUrl([
                'image_id' => 3,
                'image_file' => 'fixture-photo-3.jpg',
            ], ['start']),
            [
                'slideshow' => '',
            ]
        );

        self::assertSame($expected, $result->slideshowUrl);
    }

    public function testRenderReturnsNullAndRendersNoThumbnailsWhenTheSelectionIsEmpty(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);

        // start=99 is past the end of a 1-item selection -> array_slice()
        // yields an empty selection.
        $result = $this->renderer->render([3], 99, 3, Section::Categories);

        self::assertNull($result->slideshowUrl);
        self::assertSame([], $result->thumbnails);
    }

    public function testRenderPrefixesTheNameWithTheRatingScoreForTheBestRatedSection(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // id=3's real fixture rating_score is 5.00 -> (string) 5.0 is '5'.
        $result = $this->renderer->render([3], 0, 1, Section::BestRated);

        self::assertSame('(5) Photo 3', $this->thumbnailAt($result, 0)['NAME']);
    }

    public function testRenderPrefixesTheNameWithTheHitCountForMostVisitedWhenShowNbHitsIsDisabled(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // The fixture's real hit count is 0 for every image, which can't
        // distinguish a correct $row['hit'] read from a bug reading a
        // different, also-zero-valued column (level, rotation, etc.) --
        // give id=3 a distinct, nonzero hit count so the rendered prefix
        // must reflect that exact value, not just "not blank".
        $this->setImageHit(3, 17);

        $result = $this->renderer->render([3], 0, 1, Section::MostVisited);

        self::assertSame('(17) Photo 3', $this->thumbnailAt($result, 0)['NAME']);
    }

    public function testRenderDoesNotPrefixTheNameForMostVisitedWhenShowNbHitsIsEnabled(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);
        // Same distinct, nonzero hit count as the disabled-path test above,
        // so this also proves NB_HITS carries the real per-image value
        // through to the array (via the "17 hits" render assertion below),
        // not just that some prefix is absent.
        $this->setImageHit(3, 17);

        $result = $this->renderer->render([3], 0, 1, Section::MostVisited);

        $thumbnail = $this->thumbnailAt($result, 0);
        self::assertSame('Photo 3', $thumbnail['NAME']);
        self::assertSame(17, $thumbnail['NB_HITS']);

        // show_nb_hits=true makes thumbnails.latte display NB_HITS via the
        // "translate_dec" filter (fixed bug: it used to call the
        // deprecated $pwg->l10n_dec() method directly) -- real end-to-end
        // render, not just the raw array value above, to catch a broken
        // filter/wiring the array check alone can't see.
        $html = $this->renderedThumbnailsHtml($result);
        self::assertStringContainsString('17 hits', $html);
    }

    public function testRenderShowsTheValidatedCommentCountWhenShowNbCommentsIsEnabled(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: true);

        // fixture: image id 3 has exactly one comment (id 3), already
        // validated -- activate_comments is 'true' in the fixture config,
        // so countValidatedByImageIds() reaches this real row.
        $result = $this->renderer->render([3], 0, 1, Section::Categories);

        self::assertSame(1, $this->thumbnailAt($result, 0)['NB_COMMENTS']);

        // thumbnails.latte renders NB_COMMENTS via the "translate_dec"
        // filter -- the other of its 2 call sites (NB_HITS is covered
        // above).
        $html = $this->renderedThumbnailsHtml($result);
        self::assertStringContainsString('1 comment', $html);
        self::assertStringNotContainsString('1 comments', $html);
    }
}
