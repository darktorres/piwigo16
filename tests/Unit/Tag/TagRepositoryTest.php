<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tag;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Piwigo\Db\AbstractRepository;
use Piwigo\Tag\TagRepository;

/**
 * Unit tests for TagRepository.
 *
 * These tests verify early-return guards and structural behaviour that does
 * not require a real database.  Integration coverage is provided by the
 * Playwright e2e suite (tag smoke tests).
 */
final class TagRepositoryTest extends TestCase
{
    private const string PREFIX = 'piwigo_';

    private function makeRepo(): TagRepository
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('createQueryBuilder');
        $repo = new TagRepository($conn, self::PREFIX);
        self::assertInstanceOf(AbstractRepository::class, $repo);
        return $repo;
    }

    public function test_findByIds_returns_empty_for_empty_input(): void
    {
        self::assertSame([], $this->makeRepo()->findByIds([]));
    }

    public function test_findCommonTags_returns_empty_for_empty_image_ids(): void
    {
        self::assertSame([], $this->makeRepo()->findCommonTags([], 10));
    }

    public function test_findByIdUrlOrName_returns_empty_when_all_params_empty(): void
    {
        self::assertSame([], $this->makeRepo()->findByIdUrlOrName());
    }

}
