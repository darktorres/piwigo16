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
        $arr = $payload->toArray();
        self::assertSame('abc123', $arr['origin_hash']);
        self::assertSame(['php_version' => '8.5'], $arr['technical']);
        self::assertSame(['nb_photos' => 42], $arr['general_stats']);
        // Always-emitted (even when empty) sections per the remote contract:
        self::assertSame([], $arr['plugins']);
        self::assertSame([], $arr['themes']);
        self::assertSame([], $arr['themes_usage']);
        self::assertSame([], $arr['languages_usage']);
        self::assertSame([], $arr['activities']);
        self::assertSame([], $arr['features']);
        self::assertSame([], $arr['apps']);
    }

    public function testToArrayOmitsEmptyOptionalSections(): void
    {
        $payload = new TelemetryPayload('h', [], []);
        $arr     = $payload->toArray();
        self::assertArrayNotHasKey('file_extensions', $arr);
        self::assertArrayNotHasKey('updates', $arr);
    }

    public function testToArrayIncludesOptionalSectionsWhenSet(): void
    {
        $payload = new TelemetryPayload(
            originHash: 'h',
            technical:  [],
            generalStats: [],
            fileExtensions: ['jpg' => ['counter' => 7, 'filesize' => 1024]],
            updates: [['from_version' => '15.0', 'to_version' => '16.0']],
        );
        $arr = $payload->toArray();
        self::assertArrayHasKey('file_extensions', $arr);
        self::assertArrayHasKey('updates', $arr);
    }
}
