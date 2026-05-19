<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Typed wrapper for the outbound telemetry payload — what
 * {@see TelemetryService::sendInfos()} POSTs to the PEM stats endpoint.
 *
 * Foundation step (F5-i / F5-j): exposes the canonical shape and the
 * `toArray()` serializer that the HTTP client consumes. The deep
 * migration of `sendInfos()` away from its mutable `$piwigoInfos[]`
 * accumulator (56 mixed accesses in TelemetryService) is the next
 * F5-j sub-step.
 */
final readonly class TelemetryPayload
{
    /**
     * @param array<string, mixed> $technical    Build/runtime metadata (php_version, db_version, container_type, …)
     * @param array<string, mixed> $generalStats Stats from AdminService::getPwgGeneralStatitics + augmentations (disk_usage, nb_photos_synced, …)
     * @param array<string, mixed> $fileExtensions  Per-extension usage from ImageRepository::findFileExtensionUsage
     * @param array<string, mixed> $extensions   Local PEM-installed extensions snapshot
     */
    public function __construct(
        public string $originHash,
        public array $technical,
        public array $generalStats,
        public array $fileExtensions = [],
        public array $extensions = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'origin_hash'   => $this->originHash,
            'technical'     => $this->technical,
            'general_stats' => $this->generalStats,
        ];
        if ($this->fileExtensions !== []) {
            $out['file_extensions'] = $this->fileExtensions;
        }
        if ($this->extensions !== []) {
            $out['extensions'] = $this->extensions;
        }
        return $out;
    }
}
