<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocIndexThumbnailsSelection;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): 5 real images, ids 1-5,
 * files 'fixture-photo-N.jpg', names 'Photo N', hit=0 for every one,
 * rating_score 4.50/3.00/5.00/2.00/NULL for ids 1-5 respectively.
 *
 * Real service construction throughout, same shape as
 * tests/Integration/NoPhotoYetRendererTest.php/PictureCommentRendererTest.php:
 * a real Template compiling the actual themes/default/template/thumbnails.tpl,
 * a real ImageRepository/CommentRepository against the fixture DB.
 */
final class CategoryDefaultRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryDefaultRenderer $renderer;

    private Template $template;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // See PictureCommentRendererTest's identical comment: skips
        // Template's own data_dir_checked write, which would otherwise
        // reach for a full RequestBootstrap dependency this test never
        // boots.
        $currentConfig->setDataDirChecked('1');
        // thumbnails.tpl reads $derivative_params (assigned from
        // ImageStdParams::get_by_type() by CategoryDefaultRenderer::render()
        // itself) -- ImageStdParams::$all_type_map starts empty until
        // load_from_db() populates it. load_from_db() reaches its own
        // repositories via a fresh EntityManagerFactory::build(DbConnection::
        // build()), independent of ConfigService/CurrentConfig entirely, so
        // it works here whether or not the fixture's derivative_settings/
        // derivative_size rows are populated (falls back to sane built-in
        // sizing if not, same as UploadServiceTest's own identical setup).
        // loadConfFromDb() below is unrelated to ImageStdParams -- it's
        // this test's own way of seeding every other real config-backed
        // display flag CategoryDefaultRenderer/thumbnails.tpl reads.
        $configService = new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Config\CurrentConfig::current());
        $configService->loadConfFromDb();
        ImageStdParams::current()->load_from_db();

        $this->conn = DbConnection::build();
        $em = EntityManagerFactory::build($this->conn);
        $imageRepo = $em->getRepository(ImageEntity::class);
        self::assertInstanceOf(ImageRepository::class, $imageRepo);
        $commentRepo = $em->getRepository(CommentEntity::class);
        self::assertInstanceOf(CommentRepository::class, $commentRepo);

        $htmlService = new HtmlService();
        // thumbnails.tpl's own {assign var=derivative
        // value=$pwg->derivative(...)} constructs a real DerivativeImage per
        // thumbnail, whose get_url() now resolves UrlServiceInterface live
        // from the container (singleton/service-locator elimination
        // campaign, Phase 6) -- $urlService below must share the same
        // container-shared RootPathOverride for setMakeFullUrl()-style
        // state to be visible across both, see that class's own docblock.
        $rootPathOverride = Kernel::container()->get(\Piwigo\Url\RootPathOverride::class);
        if (! $rootPathOverride instanceof \Piwigo\Url\RootPathOverride) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Url\RootPathOverride::class);
        }
        $urlService = \Piwigo\Tests\Support\UrlServiceTestFactory::build($htmlService, $rootPathOverride);

        $this->renderer = new CategoryDefaultRenderer($htmlService, $this->buildTemplate(), $imageRepo, $commentRepo, $urlService, new SessionService($em->getRepository(SessionEntity::class), \Piwigo\Config\CurrentConfig::current()), EventDispatcher::get(), ImageStdParams::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Config\CurrentConfig::current());
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Restore the fixture's real hit=0 for id=3 in case a test mutated
        // it via setImageHit() below.
        $this->conn->executeStatement('UPDATE ' . \Piwigo\Db\Tables::images() . ' SET hit = 0 WHERE id = 3');
        parent::tearDown();
    }

    private function setImageHit(int $imageId, int $hit): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . \Piwigo\Db\Tables::images() . ' SET hit = :hit WHERE id = :id',
            ['hit' => $hit, 'id' => $imageId]
        );
    }

    private function buildTemplate(): Template
    {
        // root/theme='default' points Smarty's template_dir at the real
        // themes/default/template/ directory thumbnails.tpl lives in, same
        // real-root shape every real Template() construction site uses.
        $this->template = \Piwigo\Tests\Support\TemplateTestFactory::build(CurrentPaths::get()->root . 'themes', 'default');

        return $this->template;
    }

    private function seedUser(bool $showNbHits, bool $showNbComments): void
    {
        CurrentUser::current()->set(User::fromUserArray([
            'id' => 3,
            'username' => 'fixture_regular_user',
            'status' => 'normal',
            'show_nb_hits' => $showNbHits,
            'show_nb_comments' => $showNbComments,
        ]));
    }

    private function renderedThumbnailsHtml(): string
    {
        $vars = $this->template->get_template_vars('THUMBNAILS');

        return is_string($vars) ? $vars : '';
    }

    public function test_render_orders_thumbnails_by_rank_not_by_the_ids_own_numeric_order(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // Deliberately out of numeric order: rank 0 => id 3, rank 1 => id 1,
        // rank 2 => id 2 -- a real transposition bug (e.g. sorting by id
        // instead of by rank) would produce a different order here.
        $this->renderer->render([3, 1, 2], 0, 3, '');

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

    public function test_render_returns_the_slideshow_url_for_the_first_ranked_picture(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);
        $urlService = \Piwigo\Tests\Support\UrlServiceTestFactory::build();

        $slideshowUrl = $this->renderer->render([3, 1, 2], 0, 3, '');

        // The first-ranked picture after sorting is id=3 (rank 0) --
        // duplicatePictureUrl()/addUrlParams() are the same real UrlService
        // methods/state CategoryDefaultRenderer itself calls internally, so
        // this proves the renderer threads through the *correct* picture
        // (id 3, file fixture-photo-3.jpg), not a mixed-up start/first item.
        $expected = $urlService->addUrlParams(
            $urlService->duplicatePictureUrl(['image_id' => 3, 'image_file' => 'fixture-photo-3.jpg'], ['start']),
            ['slideshow' => '']
        );

        self::assertSame($expected, $slideshowUrl);
    }

    public function test_render_returns_null_and_renders_no_thumbnails_when_the_selection_is_empty(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);

        // start=99 is past the end of a 1-item selection -> array_slice()
        // yields an empty selection.
        $slideshowUrl = $this->renderer->render([3], 99, 3, '');

        self::assertNull($slideshowUrl);
        $html = $this->renderedThumbnailsHtml();
        for ($id = 1; $id <= 5; $id++) {
            self::assertStringNotContainsString('Photo ' . $id, $html);
        }
    }

    public function test_render_prefixes_the_name_with_the_rating_score_for_the_best_rated_section(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // id=3's real fixture rating_score is 5.00 -> (string) 5.0 is '5'.
        $this->renderer->render([3], 0, 1, 'best_rated');

        $html = $this->renderedThumbnailsHtml();
        self::assertStringContainsString('(5) Photo 3', $html);
    }

    public function test_render_prefixes_the_name_with_the_hit_count_for_most_visited_when_show_nb_hits_is_disabled(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: false);

        // The fixture's real hit count is 0 for every image, which can't
        // distinguish a correct $row['hit'] read from a bug reading a
        // different, also-zero-valued column (level, rotation, etc.) --
        // give id=3 a distinct, nonzero hit count so the rendered prefix
        // must reflect that exact value, not just "not blank".
        $this->setImageHit(3, 17);

        $this->renderer->render([3], 0, 1, 'most_visited');

        $html = $this->renderedThumbnailsHtml();
        self::assertStringContainsString('(17) Photo 3', $html);
    }

    public function test_render_does_not_prefix_the_name_for_most_visited_when_show_nb_hits_is_enabled(): void
    {
        $this->seedUser(showNbHits: true, showNbComments: false);
        // Same distinct, nonzero hit count as the disabled-path test above,
        // so this also proves NB_HITS carries the real per-image value
        // through to the template (via the "17 hits" assertion below), not
        // just that some prefix is absent.
        $this->setImageHit(3, 17);

        // show_nb_hits=true makes thumbnails.tpl assign+display NB_HITS via
        // the "translate_dec" modifier (fixed bug: it used to call the
        // deprecated $pwg->l10n_dec() method directly).
        $this->renderer->render([3], 0, 1, 'most_visited');

        $html = $this->renderedThumbnailsHtml();
        self::assertStringNotContainsString('(17) Photo 3', $html);
        self::assertStringContainsString('Photo 3', $html);
        // Confirms NB_HITS actually reached the template with the real,
        // distinct per-image value (not just that no prefix rendered).
        self::assertStringContainsString('17 hits', $html);
    }

    public function test_render_throws_when_a_loc_index_thumbnails_selection_handler_returns_something_other_than_a_loc_index_thumbnails_selection_instance(): void
    {
        // addEventHandler(), not addTypedHandler() -- a real plugin
        // handler is untyped from PHPStan's perspective, and this test
        // exercises dispatchChange()'s own runtime enforcement, not a
        // static one.
        EventDispatcher::get()->addEventHandler(LocIndexThumbnailsSelection::class, static fn (): int => 42);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must return an instance of');

        try {
            $this->seedUser(showNbHits: false, showNbComments: false);
            $this->renderer->render([3, 1, 2], 0, 3, '');
        } finally {
            EventDispatcher::get()->reset();
        }
    }

    public function test_render_shows_the_validated_comment_count_when_show_nb_comments_is_enabled(): void
    {
        $this->seedUser(showNbHits: false, showNbComments: true);

        // fixture: image id 3 has exactly one comment (id 3), already
        // validated -- activate_comments is 'true' in the fixture config,
        // so countValidatedByImageIds() reaches this real row.
        $this->renderer->render([3], 0, 1, '');

        $html = $this->renderedThumbnailsHtml();
        // thumbnails.tpl renders NB_COMMENTS via the "translate_dec"
        // modifier (fixed bug: it used to call the deprecated
        // $pwg->l10n_dec() method directly) -- exercises the other of the
        // 2 call sites that bug touched (NB_HITS is covered above).
        self::assertStringContainsString('1 comment', $html);
        self::assertStringNotContainsString('1 comments', $html);
    }
}
