<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Typed wrapper for the outbound telemetry payload — what
 * {@see TelemetryService::sendInfos()} POSTs to the PEM stats endpoint.
 *
 * Sections mirror the historical `$piwigoInfos` accumulator that the
 * remote endpoint expects. Each section is a typed slot here; sendInfos
 * builds them through private per-section helpers, then composes the
 * final payload through this DTO.
 */
final readonly class TelemetryPayload
{
    /**
     * @param array<string, mixed>             $technical       Build/runtime metadata (php_version, db_version, container_type, …)
     * @param array<string, mixed>             $generalStats    Stats from AdminService::getPwgGeneralStatitics + augmentations
     * @param array<string, array{counter:int, filesize:int}> $fileExtensions Per-extension usage
     * @param list<string>                     $plugins         Plugin entries as `#eid/codename/version`
     * @param list<string>                     $themes          Theme entries as `#eid/codename/version`
     * @param array<string, int>               $themesUsage     theme_id → user count
     * @param array<string, int>               $languagesUsage  language code → user count
     * @param array<string, array<string, mixed>> $activities   Object-name → action-name → counter
     * @param list<array<string, mixed>>       $updates         Core-upgrade history entries
     * @param array<string, string>            $features        feature_name → 'yes'|'no'
     * @param array<string, array<string, mixed>> $apps         App name → counter + first/last encounter
     */
    public function __construct(
        public string $originHash,
        public array $technical,
        public array $generalStats,
        public array $fileExtensions = [],
        public array $plugins = [],
        public array $themes = [],
        public array $themesUsage = [],
        public array $languagesUsage = [],
        public array $activities = [],
        public array $updates = [],
        public array $features = [],
        public array $apps = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'origin_hash'     => $this->originHash,
            'technical'       => $this->technical,
            'general_stats'   => $this->generalStats,
        ];
        if ($this->fileExtensions !== []) {
            $out['file_extensions'] = $this->fileExtensions;
        }
        $out['plugins']         = $this->plugins;
        $out['themes']          = $this->themes;
        $out['themes_usage']    = $this->themesUsage;
        $out['languages_usage'] = $this->languagesUsage;
        $out['activities']      = $this->activities;
        if ($this->updates !== []) {
            $out['updates'] = $this->updates;
        }
        $out['features'] = $this->features;
        $out['apps']     = $this->apps;
        return $out;
    }
}
