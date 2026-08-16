<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use LogicException;
use Override;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Comment\CommentEntity;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
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

        $this->renderer = new CategoryDefaultRenderer($htmlService, $this->buildTemplate(), $imageRepo, $commentRepo, $urlService, new SessionService($em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), ImageStdParamsTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), $processCache, PageStateTestFactory::get());
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

    private function renderedThumbnailsHtml(): string
    {
        // assignVarFromTemplate() wraps THUMBNAILS in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $vars = $this->template->getTemplateVars('THUMBNAILS');
        if ($vars instanceof Html) {
            return (string) $vars;
        }

        return is_string($vars) ? $vars : '';
    }

    public function testRenderOrdersThumbnailsByRankNotByTheIdsOwnNumericOrder(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // Deliberately out of numeric order: rank 0 => id 3, rank 1 => id 1,
        // rank 2 => id 2 -- a real transposition bug (e.g. sorting by id
        // instead of by rank) would produce a different order here.
        $this->renderer->render([3, 1, 2], 0, 3, Section::Categories);

        $html = $this->renderedThumbnailsHtml();
        $posPhoto3 = strpos($html, 'Photo 3');
        $posPhoto1 = strpos($html, 'Photo 1');
        $posPhoto2 = strpos($html, 'Photo 2');

        self::assertIsInt($posPhoto3);
        self::assertIsInt($posPhoto1);
        self::assertIsInt($posPhoto2);
        self::assertTrue($posPhoto3 < $posPhoto1, 'Photo 3 (rank 0) must render before Photo 1 (rank 1)');
        self::assertTrue($posPhoto1 < $posPhoto2, 'Photo 1 (rank 1) must render before Photo 2 (rank 2)');
    }

    public function testRenderReturnsTheSlideshowUrlForTheFirstRankedPicture(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);
        $urlService = UrlServiceTestFactory::build();

        $slideshowUrl = $this->renderer->render([3, 1, 2], 0, 3, Section::Categories);

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

        self::assertSame($expected, $slideshowUrl);
    }

    public function testRenderReturnsNullAndRendersNoThumbnailsWhenTheSelectionIsEmpty(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);

        // start=99 is past the end of a 1-item selection -> array_slice()
        // yields an empty selection.
        $slideshowUrl = $this->renderer->render([3], 99, 3, Section::Categories);

        self::assertNull($slideshowUrl);
        $html = $this->renderedThumbnailsHtml();
        for ($id = 1; $id <= 5; $id++) {
            self::assertStringNotContainsString('Photo ' . $id, $html);
        }
    }

    public function testRenderPrefixesTheNameWithTheRatingScoreForTheBestRatedSection(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // id=3's real fixture rating_score is 5.00 -> (string) 5.0 is '5'.
        $this->renderer->render([3], 0, 1, Section::BestRated);

        $html = $this->renderedThumbnailsHtml();
        self::assertStringContainsString('(5) Photo 3', $html);
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

        $this->renderer->render([3], 0, 1, Section::MostVisited);

        $html = $this->renderedThumbnailsHtml();
        self::assertStringContainsString('(17) Photo 3', $html);
    }

    public function testRenderDoesNotPrefixTheNameForMostVisitedWhenShowNbHitsIsEnabled(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);
        // Same distinct, nonzero hit count as the disabled-path test above,
        // so this also proves NB_HITS carries the real per-image value
        // through to the template (via the "17 hits" assertion below), not
        // just that some prefix is absent.
        $this->setImageHit(3, 17);

        // show_nb_hits=true makes thumbnails.latte assign+display NB_HITS
        // via the "translate_dec" filter (fixed bug: it used to call the
        // deprecated $pwg->l10n_dec() method directly).
        $this->renderer->render([3], 0, 1, Section::MostVisited);

        $html = $this->renderedThumbnailsHtml();
        self::assertStringNotContainsString('(17) Photo 3', $html);
        self::assertStringContainsString('Photo 3', $html);
        // Confirms NB_HITS actually reached the template with the real,
        // distinct per-image value (not just that no prefix rendered).
        self::assertStringContainsString('17 hits', $html);
    }

    public function testRenderShowsTheValidatedCommentCountWhenShowNbCommentsIsEnabled(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: true);

        // fixture: image id 3 has exactly one comment (id 3), already
        // validated -- activate_comments is 'true' in the fixture config,
        // so countValidatedByImageIds() reaches this real row.
        $this->renderer->render([3], 0, 1, Section::Categories);

        $html = $this->renderedThumbnailsHtml();
        // thumbnails.latte renders NB_COMMENTS via the "translate_dec"
        // filter -- the other of its 2 call sites (NB_HITS is covered
        // above).
        self::assertStringContainsString('1 comment', $html);
        self::assertStringNotContainsString('1 comments', $html);
    }
}
