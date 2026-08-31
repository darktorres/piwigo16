<?php

declare(strict_types=1);

namespace Piwigo\GeoIp;

/**
 * One IP lookup's result. `$fullName` joins city/region/country the same
 * order the original jquery.geoip.js callback built it client-side
 * ("city, region, country", skipping any part that's empty), kept here so
 * both real callers (history.ts's connection log, rating_user.ts's
 * anonymous-rater tooltip) render an identical string without duplicating
 * the join logic.
 */
final readonly class GeoIpResult
{
    public function __construct(
        public ?string $city,
        public ?string $regionName,
        public ?string $countryName,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    public function fullName(): string
    {
        return implode(', ', array_filter([$this->city, $this->regionName, $this->countryName], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
