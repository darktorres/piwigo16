<?php

declare(strict_types=1);

namespace Piwigo\GeoIp;

use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Piwigo\Core\Paths;

/**
 * Real replacement for jquery.geoip.js's client-side call to the long-dead
 * http://freegeoip.net/json/<ip> JSONP endpoint (docs/PLAN.md P49-B group
 * 1's own finding). Reads a locally-downloaded DB-IP City Lite `.mmdb`
 * file instead of calling any live third-party service -- see
 * GeoIpDatabaseUpdater for how that file gets there.
 *
 * The database's field shape (city.names/subdivisions[].names/
 * country.names/location.{latitude,longitude}) is DB-IP's own
 * GeoIP2-City-compatible schema, documented at
 * https://db-ip.com/db/format/ip-to-city-lite/mmdb.html -- not something
 * this reader's own generic MaxMind\Db\Reader package defines, since that
 * package only parses the binary container format, not any particular
 * vendor's field layout.
 */
final readonly class GeoIpLookupService
{
    public function __construct(
        private Paths $paths,
    ) {}

    /**
     * Null when the database hasn't been downloaded yet
     * (MaintenanceGeoIpUpdateCommand never having run), is corrupt, or the
     * IP has no match -- all three are the same "nothing to show" case to
     * every real caller.
     */
    public function lookup(string $ip): ?GeoIpResult
    {
        if (! is_file($this->databasePath())) {
            return null;
        }

        try {
            $reader = new Reader($this->databasePath());

            try {
                $record = $reader->get($ip);
            } finally {
                $reader->close();
            }
        } catch (InvalidDatabaseException) {
            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $city = $this->localizedName($record['city'] ?? null);
        $region = $this->localizedName(is_array($record['subdivisions'] ?? null) ? ($record['subdivisions'][0] ?? null) : null);
        $country = $this->localizedName($record['country'] ?? null);
        $location = is_array($record['location'] ?? null) ? $record['location'] : [];

        $result = new GeoIpResult(
            city: $city,
            regionName: $region,
            countryName: $country,
            latitude: is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
            longitude: is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
        );

        return $result->fullName() === '' ? null : $result;
    }

    public function isAvailable(): bool
    {
        return is_file($this->databasePath());
    }

    /**
     * Static so GeoIpDatabaseUpdater can resolve the install target
     * without constructing a whole lookup service just for a path.
     */
    public static function databasePathFor(Paths $paths): string
    {
        return $paths->data . 'geoip/dbip-city-lite.mmdb';
    }

    public function databasePath(): string
    {
        return self::databasePathFor($this->paths);
    }

    /**
     * @param mixed $node the record's `city`/`subdivisions[n]`/`country`
     *                     entry -- each shaped `{names: {<lang>: string}}`
     */
    private function localizedName(mixed $node): ?string
    {
        if (! is_array($node) || ! is_array($node['names'] ?? null)) {
            return null;
        }

        $name = $node['names']['en'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
