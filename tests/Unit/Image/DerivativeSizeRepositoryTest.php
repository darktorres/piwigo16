<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeSizeRepository;
use Piwigo\Image\SizingParams;

final class DerivativeSizeRepositoryTest extends TestCase
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

    public function testLoadAllPartitionsByEnabledFlag(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['name' => 'square', 'enabled' => 1, 'max_width' => 120, 'max_height' => 120, 'max_crop' => 1.0, 'min_width' => 120, 'min_height' => 120, 'sharpen' => 0, 'last_mod_time' => 1700000000],
            ['name' => '3xlarge', 'enabled' => 0, 'max_width' => 2232, 'max_height' => 1674, 'max_crop' => 0, 'min_width' => null, 'min_height' => null, 'sharpen' => 0, 'last_mod_time' => 0],
        ]);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $rows = (new DerivativeSizeRepository($conn))->loadAll();
        self::assertArrayHasKey('square', $rows->enabled);
        self::assertArrayHasKey('3xlarge', $rows->disabled);
        self::assertSame(120, $rows->enabled['square']->sizing->ideal_size[0]);
        self::assertSame(1.0, $rows->enabled['square']->sizing->max_crop);
        self::assertNull($rows->disabled['3xlarge']->sizing->min_size);
    }

    public function testHasAnyReturnsTrueWhenCountPositive(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(11);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        self::assertTrue((new DerivativeSizeRepository($conn))->hasAny());
    }

    public function testReplaceAllDeletesThenInsertsEnabledAndDisabled(): void
    {
        $statements = [];
        $conn = $this->createStub(Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        $squareParams = new DerivativeParams(new SizingParams([120, 120], 1.0, [120, 120]));
        $largeParams  = new DerivativeParams(new SizingParams([1008, 756]));
        $disabledParams = new DerivativeParams(new SizingParams([3000, 2250]));

        (new DerivativeSizeRepository($conn))->replaceAll(
            ['square' => $squareParams, 'large' => $largeParams],
            ['4xlarge' => $disabledParams]
        );

        self::assertCount(4, $statements);
        self::assertStringContainsString('DELETE FROM piwigo_derivative_size', $statements[0]['sql']);
        self::assertStringContainsString('INSERT INTO piwigo_derivative_size', $statements[1]['sql']);
        // enabled rows first
        self::assertSame(1, $statements[1]['params'][1]);
        self::assertSame('square', $statements[1]['params'][0]);
        self::assertSame('large', $statements[2]['params'][0]);
        // disabled row last
        self::assertSame(0, $statements[3]['params'][1]);
        self::assertSame('4xlarge', $statements[3]['params'][0]);
    }
}
