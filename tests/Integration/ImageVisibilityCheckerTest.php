<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Users\User;

/**
 * No prior coverage existed for this [SEC-33] class at all -- found via
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

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();
        $this->checker = new ImageVisibilityChecker(new PermissionRepository(EntityManagerFactory::build($this->conn)), CurrentUserTestFactory::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentUserTestFactory::get()->reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    private static function setCurrentUserForbiddenCategories(string $forbiddenCategories): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 2,
            'status' => 'normal',
            'forbidden_categories' => $forbiddenCategories,
            'level' => '0',
        ]));
    }

    public function testIsVisibleToUserReturnsTrueWhenNothingIsForbidden(): void
    {
        self::setCurrentUserForbiddenCategories('0');

        self::assertTrue($this->checker->isVisibleToUser(ImageId::from(1)));
        self::assertTrue($this->checker->isVisibleToUser(ImageId::from(4)));
    }

    public function testIsVisibleToUserReturnsFalseForAnImageInAForbiddenCategory(): void
    {
        self::setCurrentUserForbiddenCategories('2');

        self::assertFalse($this->checker->isVisibleToUser(ImageId::from(4)));
        self::assertFalse($this->checker->isVisibleToUser(ImageId::from(5)));
    }

    public function testIsVisibleToUserReturnsTrueForAnImageNotInAForbiddenCategory(): void
    {
        self::setCurrentUserForbiddenCategories('2');

        self::assertTrue($this->checker->isVisibleToUser(ImageId::from(1)));
    }

    /**
     * A permission revocation (CurrentUser::forbiddenCategories changing
     * mid-request-lifecycle here, simulating the next real request after
     * an admin action) must be reflected immediately, not served from a
     * frozen prior value.
     */
    public function testIsVisibleToUserReflectsARevocationOnTheSameConnection(): void
    {
        self::setCurrentUserForbiddenCategories('0');
        self::assertTrue($this->checker->isVisibleToUser(ImageId::from(4)));

        self::setCurrentUserForbiddenCategories('2');
        self::assertFalse($this->checker->isVisibleToUser(ImageId::from(4)));
    }
}
