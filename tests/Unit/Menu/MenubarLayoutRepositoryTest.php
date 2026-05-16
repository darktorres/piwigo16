<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Menu;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
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
        $this->repo = new MenubarLayoutRepository(new ConfigService($conn));
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

    public function testLoadDecodesSerializedArrayAndPreservesPositions(): void
    {
        Config::loadArray(['blk_menubar' => serialize(['mbLinks' => 50, 'mbTags' => -100, 'mbMenu' => 25])]);

        self::assertSame(['mbLinks' => 50, 'mbTags' => -100, 'mbMenu' => 25], $this->repo->load());
    }

    public function testLoadFiltersOutNonStringKeysAndNonIntValues(): void
    {
        Config::loadArray(['blk_menubar' => serialize(['mbLinks' => 50, 7 => 100, 'mbTags' => 'not-int'])]);

        self::assertSame(['mbLinks' => 50], $this->repo->load());
    }

    public function testLoadReturnsEmptyArrayWhenSerializedDataIsMalformed(): void
    {
        Config::loadArray(['blk_menubar' => 'this-is-not-serialized-data']);
        self::assertSame([], $this->repo->load());
    }
}
