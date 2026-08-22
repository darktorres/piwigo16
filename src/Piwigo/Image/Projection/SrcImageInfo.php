<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\SrcImage}'s own constructor input -- confirmed by
 * tracing its ~19 real construction sites: full `images` table rows,
 * but also category-listing rows and upload-pipeline rows that merely
 * carry a subset of the same field names (`id`/`path`/
 * `representative_ext`/`width`/`height`/`rotation`). Every field here
 * degrades the same way the old array-key reads did: missing/malformed
 * input narrows to a safe default rather than erroring. `file` was
 * dropped, not carried over -- the old array-based constructor only
 * ever used it to compute a `file_ext` key that was written into its
 * own local `$infos` copy and never read again anywhere in the class, a
 * genuinely dead computation this retype exposed.
 *
 * `$dimensionsUnavailable` preserves a real 3-way distinction the old
 * `array_key_exists('width', $infos)` check made that a plain `?int
 * $width` can't reproduce alone: "the row never carried a width column
 * at all" (this flag true -- {@see SrcImage::getSize()} fatals rather
 * than attempting a filesystem fallback) is a genuinely different state
 * from "the row carried `width`/`height` but they came back null/absent
 * individually" (this flag false, `$width`/`$height` null -- getSize()
 * falls back to a real `getimagesize()` read).
 */
final readonly class SrcImageInfo
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $representativeExt = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $rotation = null,
        public bool $dimensionsUnavailable = false,
    ) {}

    /**
     * @param array<string, mixed> $row assoc array of data from the images table (or a compatible subset)
     */
    public static function fromRow(array $row): self
    {
        // isset()'s own semantics ("key present AND non-null"), not
        // array_key_exists() -- matches SrcImage's original `isset(
        // $infos['width']) && isset($infos['height'])` gate exactly.
        // Only inside this gate does a non-numeric value default to 0
        // (not null) -- that 0-default never applied outside it either.
        $widthGiven = isset($row['width']);
        $heightGiven = isset($row['height']);

        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            path: is_string($row['path'] ?? null) ? $row['path'] : '',
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            width: $widthGiven && $heightGiven ? (is_numeric($row['width']) ? (int) $row['width'] : 0) : null,
            height: $widthGiven && $heightGiven ? (is_numeric($row['height']) ? (int) $row['height'] : 0) : null,
            rotation: is_numeric($row['rotation'] ?? null) ? (int) $row['rotation'] : null,
            dimensionsUnavailable: ! ($widthGiven && $heightGiven) && ! array_key_exists('width', $row),
        );
    }
}
