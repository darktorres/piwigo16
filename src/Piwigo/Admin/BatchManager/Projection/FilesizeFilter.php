<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Admin\BatchManager\FilterResolver::filesizePhotoIds()}'s
 * parameter object (P17-23 Phase 8, "cross-domain generic-row-reader"
 * elimination) -- `$_SESSION['bulk_manager_filter']['filesize']`'s 2
 * optional bounds (kB). The one real caller (`BatchManagerSubController::
 * computeCurrentSet()`) builds this via {@see self::fromArray()} from the
 * session sub-array it already has raw access to. {@see self::isEmpty()}
 * replaces the old `$where === []` check that used to live inside
 * `filesizePhotoIds()` itself.
 */
final readonly class FilesizeFilter
{
    public function __construct(
        public ?float $min = null,
        public ?float $max = null,
    ) {}

    /**
     * @param array<string, mixed> $filesize
     */
    public static function fromArray(array $filesize): self
    {
        return new self(
            min: isset($filesize['min']) && is_numeric($filesize['min']) ? (float) $filesize['min'] : null,
            max: isset($filesize['max']) && is_numeric($filesize['max']) ? (float) $filesize['max'] : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->min === null && $this->max === null;
    }
}
