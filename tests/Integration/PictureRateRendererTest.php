<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Auth\AccessControl;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Common\ValueObject\UserId;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * The Unit sibling (tests/Unit/Picture/PictureRateRendererTest.php) only
 * exercises rate_enabled=false and the rating_score=null "stays DB-free"
 * branches -- every branch that needs a real RateRepository row (the
 * summary count/average, and the current user's own rate lookup, for both
 * a classic logged-in user and an anonymous guest) lives here.
 */
final class PictureRateRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private RateRepository $repo;

    private PictureRateRenderer $renderer;

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
        $currentConfig->setRateEnabled(true);

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(RateEntity::class);
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current()));
        CurrentTemplate::current()->set(TemplateTestFactory::build());

        // The fixture itself seeds rate rows for element_id=1 (real
        // sample data) -- each test inserts its own rows for the exact
        // same (user_id, element_id) pairs, which collides with the
        // fixture's own seeded rows on this table's composite primary key
        // when this test runs before any other test's tearDown() has
        // cleared them. Clearing here first makes every test deterministic
        // regardless of run order.
        $this->conn->executeStatement('DELETE FROM ' . Tables::rate() . ' WHERE element_id = 1');

        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }
        $this->renderer = new PictureRateRenderer($accessControl, $this->repo, CurrentUser::current(), CurrentTemplate::current(), CurrentConfig::current());
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        CurrentUser::current()->reset();
        $this->conn->executeStatement('DELETE FROM ' . Tables::rate() . ' WHERE element_id = 1');
        parent::tearDown();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function picture(int $imageId, float $ratingScore): array
    {
        return [
            'current' => [
                'id' => (string) $imageId,
                'rating_score' => $ratingScore,
            ],
        ];
    }

    private function urlService(): UrlService
    {
        return UrlServiceTestFactory::build();
    }

    public function test_render_computes_the_rate_summary_and_the_current_classic_users_own_rate(): void
    {
        // 3 real votes (5, 3, 4) on image 1 -- count=3, average=4.0.
        $this->conn->executeStatement("INSERT INTO " . Tables::rate() . " (user_id, element_id, anonymous_id, rate) VALUES (1, 1, '', 5), (3, 1, '', 3), (4, 1, '', 4)");

        CurrentUser::current()->set(new User(
            id: UserId::from(3),
            username: 'fixture_user_3',
            email: '',
            language: '',
            theme: '',
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $this->renderer->render(1, $this->urlService(), $this->picture(1, 87.5), '/picture.php?/1');

        $rateSummary = CurrentTemplate::current()->get()->get_template_vars('rate_summary');
        self::assertSame(['count' => 3, 'score' => 87.5, 'average' => 4.0], $rateSummary);

        $rating = CurrentTemplate::current()->get()->get_template_vars('rating');
        self::assertIsArray($rating);
        // Classic user (not anonymous) -- user_id=3's own vote (3), matched
        // with a null anonymous_id (isAuthorizeStatus(Classic) skips the
        // trimmed-IP computation entirely).
        self::assertSame(3, $rating['USER_RATE']);
    }

    public function test_render_computes_the_current_anonymous_guests_own_rate_from_the_trimmed_ip(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setRateAnonymous(true);
        $currentConfig->setGuestId(2);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        // guestId (2) voted 5, keyed by the trimmed (3-octet) IP prefix --
        // the exact anonymous_id shape render() itself computes.
        $this->conn->executeStatement("INSERT INTO " . Tables::rate() . " (user_id, element_id, anonymous_id, rate) VALUES (2, 1, '203.0.113', 5)");

        CurrentUser::current()->set(new User(
            id: UserId::from(2),
            username: 'guest',
            email: '',
            language: '',
            theme: '',
            status: UserStatus::Guest,
            enabledHigh: false,
        ));

        try {
            $this->renderer->render(1, $this->urlService(), $this->picture(1, 90.0), '/picture.php?/1');

            $rating = CurrentTemplate::current()->get()->get_template_vars('rating');
            self::assertIsArray($rating);
            self::assertSame(5, $rating['USER_RATE']);
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    public function test_render_leaves_user_rate_null_when_the_summary_has_no_votes_yet(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setRateAnonymous(true);
        // rating_score=0.0 is still a real, non-null score (the `!== null`
        // guard doesn't treat 0.0 as absent) -- findRateSummaryForElement()
        // still runs its real COUNT/AVG query, just against zero matching
        // rows (tearDown() clears element_id=1's rate rows, and this test
        // inserts none), so count=0/average=null come from real aggregate
        // SQL semantics, not the method's own `$row === false` fallback.
        $this->renderer->render(1, $this->urlService(), $this->picture(1, 0.0), '/picture.php?/1');

        $rateSummary = CurrentTemplate::current()->get()->get_template_vars('rate_summary');
        self::assertSame(['count' => 0, 'score' => 0.0, 'average' => null], $rateSummary);

        $rating = CurrentTemplate::current()->get()->get_template_vars('rating');
        self::assertIsArray($rating);
        // count === 0 -- findUserRate() is never even called.
        self::assertNull($rating['USER_RATE']);
    }
}
