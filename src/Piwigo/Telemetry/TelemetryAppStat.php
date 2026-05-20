<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Per-app entry inside the `apps` section of {@see TelemetryPayload}:
 * aggregated `counter`, plus the earliest and latest occurrence of a
 * user agent matching one of the known app fingerprints (Piwigo iOS,
 * Lightroom, Aperture, …).
 *
 * Replaces the inner `array<string, mixed>` shape that
 * `TelemetryService::buildAppsStats()` previously emitted.
 */
final readonly class TelemetryAppStat
{
    public function __construct(
        public int    $counter,
        public string $firstEncounter,
        public string $lastEncounter,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'counter'         => $this->counter,
            'first_encounter' => $this->firstEncounter,
            'last_encounter'  => $this->lastEncounter,
        ];
    }
}
