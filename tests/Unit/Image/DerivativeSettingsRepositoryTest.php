<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Image\DerivativeSettingsRepository;
use Piwigo\Image\WatermarkParams;

final class DerivativeSettingsRepositoryTest extends TestCase
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

    public function testLoadReturnsDefaultsWhenRowAbsent(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $data = (new DerivativeSettingsRepository($conn))->load();
        self::assertSame(95, $data['quality']);
        self::assertSame('', $data['watermark']->file);
        self::assertSame([], $data['custom']);
    }

    public function testLoadDecodesQualityWatermarkAndCustom(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'default_quality' => 88,
            'watermark_json'  => '{"file":"./watermark.png","min_size":[640,480],"xpos":50,"ypos":50,"xrepeat":0,"yrepeat":0,"opacity":80}',
            'custom_json'     => '{"e120x90":1700000000,"e240x180":1700001000}',
        ]);

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $data = (new DerivativeSettingsRepository($conn))->load();
        self::assertSame(88, $data['quality']);
        self::assertSame('./watermark.png', $data['watermark']->file);
        self::assertSame([640, 480], $data['watermark']->min_size);
        self::assertSame(80, $data['watermark']->opacity);
        self::assertSame(['e120x90' => 1700000000, 'e240x180' => 1700001000], $data['custom']);
    }

    public function testSaveUpsertsSingletonRow(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO piwigo_derivative_settings'),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 1
                        && $params[1] === 95
                        && is_string($params[2]) && str_contains($params[2], '"file":""')
                        && $params[3] === '{"foo":42}';
                })
            );

        $repo = new DerivativeSettingsRepository($conn);
        $repo->save(95, new WatermarkParams(), ['foo' => 42]);
    }
}
