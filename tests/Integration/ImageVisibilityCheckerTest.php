<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * No prior coverage existed for this [SEC-33] class at all -- a real gap
 * this closes, found the same way Stage 4b's own UserServiceTest gap was:
 * a failing Browser test (DerivativePermissionTest), not by this class's
 * own (nonexistent) tests. Same fixture shape as ForbiddenCategoriesCacheTest:
 * category 1 ("Sample Album") has images 1-3; category 2 ("Nested Sub
 * Album", a subcategory of 1) has images 4-5; each image belongs to
 * exactly one category.
 */
final class ImageVisibilityCheckerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ImageVisibilityChecker $checker;

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

        $this->conn = DbConnection::build();
        $this->checker = new ImageVisibilityChecker(new PermissionRepository(EntityManagerFactory::build($this->conn)));
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentUser::reset();
        parent::tearDown();
    }

    private static function setCurrentUserForbiddenCategories(string $forbiddenCategories): void
    {
        CurrentUser::set(User::fromUserArray([
            'id' => 2,
            'status' => 'normal',
            'forbidden_categories' => $forbiddenCategories,
            'level' => '0',
        ]));
    }

    public function test_is_visible_to_user_returns_true_when_nothing_is_forbidden(): void
    {
        self::setCurrentUserForbiddenCategories('0');

        self::assertTrue($this->checker->isVisibleToUser(1));
        self::assertTrue($this->checker->isVisibleToUser(4));
    }

    public function test_is_visible_to_user_returns_false_for_an_image_in_a_forbidden_category(): void
    {
        self::setCurrentUserForbiddenCategories('2');

        self::assertFalse($this->checker->isVisibleToUser(4));
        self::assertFalse($this->checker->isVisibleToUser(5));
    }

    public function test_is_visible_to_user_returns_true_for_an_image_not_in_a_forbidden_category(): void
    {
        self::setCurrentUserForbiddenCategories('2');

        self::assertTrue($this->checker->isVisibleToUser(1));
    }

    /**
     * Gap-closure Stage 4g's own real regression, reproduced directly: a
     * permission revocation (CurrentUser::forbiddenCategories changing
     * mid-request-lifecycle here, simulating the next real request after
     * an admin action) must be reflected immediately, not served from a
     * frozen prior value.
     */
    public function test_is_visible_to_user_reflects_a_revocation_on_the_same_connection(): void
    {
        self::setCurrentUserForbiddenCategories('0');
        self::assertTrue($this->checker->isVisibleToUser(4));

        self::setCurrentUserForbiddenCategories('2');
        self::assertFalse($this->checker->isVisibleToUser(4));
    }
}
