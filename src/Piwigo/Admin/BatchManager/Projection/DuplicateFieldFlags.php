<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Admin\BatchManager\FilterResolver::duplicateFieldsFromFilter()}/
 * `resolvePrefilter()`'s "duplicates" branch parameter object (P17-23
 * Phase 8, "cross-domain generic-row-reader" elimination). The 4 flags
 * are presence-only booleans from `$_SESSION['bulk_manager_filter']`
 * (`duplicates_filename`/`duplicates_checksum`/`duplicates_date`/
 * `duplicates_dimensions`) -- the caller (`BatchManagerSubController::
 * computeCurrentSet()`) builds this once via {@see self::fromBulkFilter()}
 * from the full session-held filter array it already has raw access to.
 */
final readonly class DuplicateFieldFlags
{
    public function __construct(
        public bool $filename = false,
        public bool $checksum = false,
        public bool $date = false,
        public bool $dimensions = false,
    ) {}

    /**
     * @param array<string, mixed> $bulkFilter
     */
    public static function fromBulkFilter(array $bulkFilter): self
    {
        return new self(
            filename: isset($bulkFilter['duplicates_filename']),
            checksum: isset($bulkFilter['duplicates_checksum']),
            date: isset($bulkFilter['duplicates_date']),
            dimensions: isset($bulkFilter['duplicates_dimensions']),
        );
    }
}
