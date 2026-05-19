<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Piwigo\Telemetry\TelemetryPayload;

final class TelemetryPayloadTest extends TestCase
{
    public function testToArrayEmitsBaseShape(): void
    {
        $payload = new TelemetryPayload(
            originHash: 'abc123',
            technical:  ['php_version' => '8.5'],
            generalStats: ['nb_photos' => 42],
        );
        self::assertSame([
            'origin_hash'   => 'abc123',
            'technical'     => ['php_version' => '8.5'],
            'general_stats' => ['nb_photos' => 42],
        ], $payload->toArray());
    }

    public function testToArrayOmitsEmptyOptionalSections(): void
    {
        $payload = new TelemetryPayload('h', [], []);
        $arr     = $payload->toArray();
        self::assertArrayNotHasKey('file_extensions', $arr);
        self::assertArrayNotHasKey('extensions', $arr);
    }

    public function testToArrayIncludesOptionalSectionsWhenSet(): void
    {
        $payload = new TelemetryPayload(
            originHash: 'h',
            technical:  [],
            generalStats: [],
            fileExtensions: ['jpg' => ['counter' => 7]],
            extensions: ['theme' => []],
        );
        $arr = $payload->toArray();
        self::assertArrayHasKey('file_extensions', $arr);
        self::assertArrayHasKey('extensions', $arr);
    }
}
