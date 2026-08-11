<?php

declare(strict_types=1);

use Piwigo\Telemetry\DatabaseInfo;
use Piwigo\Telemetry\EnvironmentInfo;
use Piwigo\Telemetry\ExtensionStats;
use Piwigo\Telemetry\GalleryStats;
use Piwigo\Telemetry\TelemetryPayload;

function buildTestPayload(): TelemetryPayload
{
    return new TelemetryPayload(
        'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
        new EnvironmentInfo('16.3.0', '8.5.0', 'Linux'),
        new DatabaseInfo('mysql', '8.0.36'),
        new GalleryStats(10, 2, 3, 4),
        new ExtensionStats(0, 1, 5),
    );
}

test('toArray exposes exactly the expected top-level shape', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect(array_keys($array))
        ->toBe([
            'install_id',
            'generated_at',
            'environment',
            'database',
            'gallery',
            'extensions',
        ]);
});

test('toArray never leaks a site url, email, ip, or raw identifier field', function (): void {
    $encoded = (string) json_encode(buildTestPayload()->toArray());

    foreach (['url', 'email', 'ip_address', 'username', 'admin'] as $piiLikeKey) {
        expect($encoded)->not->toContain('"' . $piiLikeKey);
    }
});

test('environment sub-shape carries only version strings', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect($array['environment'])->toBe([
        'piwigo_version' => '16.3.0',
        'php_version' => '8.5.0',
        'os_family' => 'Linux',
    ]);
});

test('database sub-shape carries driver and server version', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect($array['database'])->toBe([
        'driver' => 'mysql',
        'server_version' => '8.0.36',
    ]);
});

test('gallery sub-shape carries only aggregate counts', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect($array['gallery'])->toBe([
        'image_count' => 10,
        'category_count' => 2,
        'user_count' => 3,
        'comment_count' => 4,
    ]);
});

test('extensions sub-shape carries only aggregate counts', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect($array['extensions'])->toBe([
        'plugin_count' => 0,
        'theme_count' => 1,
        'language_count' => 5,
    ]);
});

test('generated_at is formatted as an ATOM timestamp string', function (): void {
    $array = buildTestPayload()
        ->toArray();

    expect($array['generated_at'])->toBe('2026-07-12T00:00:00+00:00');
});
