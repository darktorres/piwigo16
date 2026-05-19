<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Search\SearchFilterViewRepository;

final class SearchFilterViewRepositoryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Config::reset();
        Config::loadArray(['db_prefix' => 'piwigo_']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testListAllDecodesEachRowConfigJson(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['name' => 'words', 'config_json' => '{"access":"everybody","default":true}'],
            ['name' => 'last_filters_conf', 'config_json' => 'true'],
            ['name' => 'malformed', 'config_json' => '!not-json'],
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('SELECT name, config_json FROM piwigo_search_filter_view'))
            ->willReturn($result);

        $repo = new SearchFilterViewRepository($conn);
        // Malformed entries (non-bool, non-array) are dropped silently
        // — the typed listAll() contract is array{access:string,default:bool}|bool only.
        self::assertSame(
            [
                'words'             => ['access' => 'everybody', 'default' => true],
                'last_filters_conf' => true,
            ],
            $repo->listAll()
        );
    }

    public function testReplaceAllWithEmptyMapJustDeletes(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->matchesRegularExpression('/^DELETE FROM piwigo_search_filter_view$/'));

        (new SearchFilterViewRepository($conn))->replaceAll([]);
    }

    public function testReplaceAllWritesOneRowPerEntryWithJsonEncodedConfig(): void
    {
        $statements = [];
        $conn       = $this->createStub(Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        (new SearchFilterViewRepository($conn))->replaceAll([
            'words'             => ['access' => 'everybody', 'default' => true],
            'last_filters_conf' => true,
        ]);

        self::assertCount(3, $statements);
        self::assertStringContainsString('DELETE FROM piwigo_search_filter_view', $statements[0]['sql']);
        self::assertStringContainsString('INSERT INTO piwigo_search_filter_view', $statements[1]['sql']);
        self::assertSame('words', $statements[1]['params'][0]);
        self::assertSame('{"access":"everybody","default":true}', $statements[1]['params'][1]);
        self::assertSame('last_filters_conf', $statements[2]['params'][0]);
        self::assertSame('true', $statements[2]['params'][1]);
    }

    public function testHasAnyReturnsTrueWhenCountPositive(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(3);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        self::assertTrue((new SearchFilterViewRepository($conn))->hasAny());
    }

    public function testHasAnyReturnsFalseWhenCountZero(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(0);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        self::assertFalse((new SearchFilterViewRepository($conn))->hasAny());
    }

    public function testDeleteAllIssuesUnconditionalDelete(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->matchesRegularExpression('/^DELETE FROM piwigo_search_filter_view$/'));

        (new SearchFilterViewRepository($conn))->deleteAll();
    }
}
