<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Menu;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Menu\MenubarLayoutRepository;

final class MenubarLayoutRepositoryTest extends TestCase
{
    private MenubarLayoutRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        Config::reset();
        $conn = $this->createStub(Connection::class);
        $repo = new ConfigRepository($conn, '');
        $this->repo = new MenubarLayoutRepository(new ConfigService($repo));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testLoadReturnsEmptyArrayWhenRowAbsent(): void
    {
        self::assertSame([], $this->repo->load());
    }

    public function testLoadReturnsEmptyArrayWhenRowIsEmptyString(): void
    {
        Config::loadArray(['blk_menubar' => '']);
        self::assertSame([], $this->repo->load());
    }

    public function testLoadDecodesJsonObjectAndPreservesPositions(): void
    {
        Config::loadArray(['blk_menubar' => json_encode(['mbLinks' => 50, 'mbTags' => -100, 'mbMenu' => 25])]);

        self::assertSame(['mbLinks' => 50, 'mbTags' => -100, 'mbMenu' => 25], $this->repo->load());
    }

    public function testLoadFiltersOutNonStringKeysAndNonIntValues(): void
    {
        // JSON object literal: int-keyed entry "7" becomes a string key after
        // decode, so the filter on is_string(...) keeps it; only the non-int
        // value should be dropped.
        Config::loadArray(['blk_menubar' => '{"mbLinks":50,"mbTags":"not-int"}']);

        self::assertSame(['mbLinks' => 50], $this->repo->load());
    }

    public function testLoadReturnsEmptyArrayWhenJsonIsMalformed(): void
    {
        Config::loadArray(['blk_menubar' => 'this-is-not-json']);
        self::assertSame([], $this->repo->load());
    }
}
