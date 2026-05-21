<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Extensions;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\IgnoredUpdatesRepository;
use Piwigo\Config\Config;

final class IgnoredUpdatesRepositoryTest extends TestCase
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

    public function testListForTypeReturnsExtensionIds(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['plugin-a', 'plugin-b']);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT extension_id FROM piwigo_extension_ignored_updates WHERE extension_type = ?'),
                ['plugins']
            )
            ->willReturn($result);

        $repo = new IgnoredUpdatesRepository($conn);
        self::assertSame(['plugin-a', 'plugin-b'], $repo->listForType(ExtensionType::Plugin));
    }

    public function testListAllReturnsNestedShape(): void
    {
        $resultPlugins = $this->createStub(Result::class);
        $resultPlugins->method('fetchFirstColumn')->willReturn(['plugin-a']);
        $resultThemes = $this->createStub(Result::class);
        $resultThemes->method('fetchFirstColumn')->willReturn([]);
        $resultLangs = $this->createStub(Result::class);
        $resultLangs->method('fetchFirstColumn')->willReturn(['lang-fr', 'lang-es']);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $params) use ($resultPlugins, $resultThemes, $resultLangs): Result {
                return match ($params[0] ?? null) {
                    'plugins'   => $resultPlugins,
                    'themes'    => $resultThemes,
                    'languages' => $resultLangs,
                    default     => throw new \LogicException('unexpected type ' . var_export($params, true)),
                };
            });

        $repo = new IgnoredUpdatesRepository($conn);
        $result = $repo->listAll();
        self::assertSame(['plugin-a'], $result->plugins);
        self::assertSame([], $result->themes);
        self::assertSame(['lang-fr', 'lang-es'], $result->languages);
    }

    public function testIsIgnoredReturnsTrueWhenCountPositive(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $repo = new IgnoredUpdatesRepository($conn);
        self::assertTrue($repo->isIgnored(ExtensionType::Theme, 'theme-x'));
    }

    public function testIsIgnoredReturnsFalseWhenCountZero(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(0);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $repo = new IgnoredUpdatesRepository($conn);
        self::assertFalse($repo->isIgnored(ExtensionType::Theme, 'theme-x'));
    }

    public function testIgnoreIssuesInsertIgnoreWithCurrentTimestamp(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT IGNORE INTO piwigo_extension_ignored_updates'),
                ['languages', 'lang-it']
            );

        (new IgnoredUpdatesRepository($conn))->ignore(ExtensionType::Language, 'lang-it');
    }

    public function testUnignoreIssuesDelete(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM piwigo_extension_ignored_updates WHERE extension_type = ? AND extension_id = ?'),
                ['plugins', 'plugin-x']
            );

        (new IgnoredUpdatesRepository($conn))->unignore(ExtensionType::Plugin, 'plugin-x');
    }

    public function testClearTypeIssuesDeleteWhereType(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM piwigo_extension_ignored_updates WHERE extension_type = ?'),
                ['themes']
            );

        (new IgnoredUpdatesRepository($conn))->clearType(ExtensionType::Theme);
    }

    public function testClearAllIssuesDeleteWithNoWhere(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->matchesRegularExpression('/^DELETE FROM piwigo_extension_ignored_updates$/'));

        (new IgnoredUpdatesRepository($conn))->clearAll();
    }

    public function testPruneStaleWithEmptyCurrentIdsClearsType(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DELETE FROM piwigo_extension_ignored_updates WHERE extension_type = ?'), ['plugins']);

        (new IgnoredUpdatesRepository($conn))->pruneStale(ExtensionType::Plugin, []);
    }

    public function testPruneStaleWithCurrentIdsIssuesDeleteWithNotIn(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('extension_id NOT IN (?)'),
                ['plugins', ['plugin-a', 'plugin-b']],
                [ParameterType::STRING, ArrayParameterType::STRING]
            );

        (new IgnoredUpdatesRepository($conn))->pruneStale(ExtensionType::Plugin, ['plugin-a', 'plugin-b']);
    }
}
