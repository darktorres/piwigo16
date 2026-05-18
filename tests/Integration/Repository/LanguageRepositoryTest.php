<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Language\LanguageRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see LanguageRepository}. The fixture
 * seeds one language: 'en_US' / 'English (US)'.
 */
final class LanguageRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private LanguageRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new LanguageRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findIdNameMap_returns_fixture_seeded_languages(): void
    {
        $map = $this->repo->findIdNameMap();
        self::assertSame('English (US)', $map['en_US']);
    }

    public function test_activate_then_findAllOrdered_lists_alphabetically(): void
    {
        $this->repo->activate('fr_FR', '17.0.0', 'Français');
        $this->repo->activate('de_DE', '17.0.0', 'Deutsch');

        $rows = $this->repo->findAllOrdered();
        $names = array_column($rows, 'name');

        self::assertSame(['Deutsch', 'English (US)', 'Français'], $names, 'ORDER BY name ASC');
    }

    public function test_deactivate_removes_language(): void
    {
        $this->repo->activate('zh_CN', '17.0.0', 'Chinese');
        self::assertArrayHasKey('zh_CN', $this->repo->findIdNameMap());

        $this->repo->deactivate('zh_CN');

        self::assertArrayNotHasKey('zh_CN', $this->repo->findIdNameMap());
    }

    public function test_reassignUsers_moves_language_for_matching_user_infos(): void
    {
        // Fixture user_infos seeds all users with 'en_US'. Move user 3 to
        // a synthetic 'pt_BR' first, then reassign back to 'en_US'.
        $this->conn->executeStatement(
            'UPDATE piwigo_user_infos SET language = ? WHERE user_id = ?',
            ['pt_BR', 3]
        );

        $this->repo->reassignUsers('pt_BR', 'en_US');

        $lang = $this->conn->executeQuery(
            'SELECT language FROM piwigo_user_infos WHERE user_id = ?',
            [3]
        )->fetchOne();
        self::assertSame('en_US', $lang);
    }
}
