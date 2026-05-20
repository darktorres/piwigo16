<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * The `technical` section of {@see TelemetryPayload}: build- and
 * runtime-environment metadata sent to the PEM stats endpoint.
 *
 * Replaces the loose `array<string, mixed>` accumulator that
 * `TelemetryService::buildTechnical()` previously returned and that
 * the payload exposed as `technical`.
 */
final readonly class TelemetryTechnical
{
    public function __construct(
        public string  $phpVersion,
        public string  $piwigoVersion,
        public string  $osVersion,
        public string  $containerType,
        public ?string $containerVersion,
        public string  $dbVersion,
        public string  $phpDatetime,
        public string  $dbDatetime,
        public string  $graphicsLibrary,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'php_version'       => $this->phpVersion,
            'piwigo_version'    => $this->piwigoVersion,
            'os_version'        => $this->osVersion,
            'container_type'    => $this->containerType,
            'container_version' => $this->containerVersion,
            'db_version'        => $this->dbVersion,
            'php_datetime'      => $this->phpDatetime,
            'db_datetime'       => $this->dbDatetime,
            'graphics_library'  => $this->graphicsLibrary,
        ];
    }
}
