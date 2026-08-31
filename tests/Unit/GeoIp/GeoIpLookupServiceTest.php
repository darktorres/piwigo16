<?php

declare(strict_types=1);

use Piwigo\Core\Paths;
use Piwigo\GeoIp\GeoIpLookupService;

// Fixture: tests/Fixtures/GeoIp/GeoIP2-City-Test.mmdb, MaxMind's own
// public test-data file (github.com/maxmind/MaxMind-DB, dual Apache-2.0/
// MIT licensed) -- schema-identical to DB-IP City Lite's real database
// (city.names/subdivisions[0].names/country.names/location.{latitude,
// longitude}, both being GeoIP2-City-compatible per DB-IP's own format
// docs), so it exercises GeoIpLookupService exactly as the real DB-IP
// file would. IPs below are that fixture's own well-known test entries.

/**
 * Builds a Paths whose `$data` points at a fresh temp dir containing the
 * fixture at the same relative path GeoIpLookupService::databasePath()
 * expects (`geoip/dbip-city-lite.mmdb`).
 */
function geoIpTestPaths(?string $fixture = __DIR__ . '/../../Fixtures/GeoIp/GeoIP2-City-Test.mmdb'): Paths
{
    $root = sys_get_temp_dir() . '/piwigo-geoip-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root . 'geoip', 0o777, true);
    if ($fixture !== null) {
        copy($fixture, $root . 'geoip/dbip-city-lite.mmdb');
    }

    return new Paths(
        root: $root,
        plugins: $root . 'plugins/',
        themes: $root . 'themes/',
        local: $root . 'local/',
        siteLocal: $root . 'local/',
        data: $root,
        derivatives: $root . 'i/',
        logs: $root . 'logs/',
        upload: $root . 'upload/',
        config: $root . 'config/',
        vendor: $root . 'vendor/',
    );
}

it('finds a full city/region/country/lat-lon match', function (): void {
    $service = new GeoIpLookupService(geoIpTestPaths());

    $result = $service->lookup('81.2.69.142');

    expect($result)
        ->not->toBeNull();
    if ($result === null) {
        throw new RuntimeException('unreachable -- asserted above');
    }
    expect($result->city)
        ->toBe('London');
    expect($result->regionName)
        ->toBe('England');
    expect($result->countryName)
        ->toBe('United Kingdom');
    expect($result->latitude)
        ->toBe(51.5142);
    expect($result->longitude)
        ->toBe(-0.0931);
    expect($result->fullName())
        ->toBe('London, England, United Kingdom');
});

it('joins fullName without empty parts when only the country matches', function (): void {
    // 67.43.156.0 in the fixture resolves to Bhutan with no city or
    // subdivisions key at all -- the real shape a country-only match
    // has, not city/region fields present-but-empty.
    $service = new GeoIpLookupService(geoIpTestPaths());

    $result = $service->lookup('67.43.156.0');

    expect($result)
        ->not->toBeNull();
    if ($result === null) {
        throw new RuntimeException('unreachable -- asserted above');
    }
    expect($result->city)
        ->toBeNull();
    expect($result->regionName)
        ->toBeNull();
    expect($result->countryName)
        ->toBe('Bhutan');
    expect($result->fullName())
        ->toBe('Bhutan');
});

it('returns null for an IP the database has no entry for', function (): void {
    $service = new GeoIpLookupService(geoIpTestPaths());

    expect($service->lookup('203.0.113.1'))
        ->toBeNull();
});

it('returns null and reports unavailable when the database has never been downloaded', function (): void {
    $service = new GeoIpLookupService(geoIpTestPaths(null));

    expect($service->isAvailable())
        ->toBeFalse();
    expect($service->lookup('81.2.69.142'))
        ->toBeNull();
});

it('reports available once the database file exists', function (): void {
    $service = new GeoIpLookupService(geoIpTestPaths());

    expect($service->isAvailable())
        ->toBeTrue();
});

it('returns null instead of throwing when the database file is corrupt', function (): void {
    $paths = geoIpTestPaths(null);
    file_put_contents($paths->data . 'geoip/dbip-city-lite.mmdb', 'not a real mmdb file');

    $service = new GeoIpLookupService($paths);

    expect($service->lookup('81.2.69.142'))
        ->toBeNull();
});
