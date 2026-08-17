<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findForMissingDerivatives()}'s own
 * row shape -- `Controller\Api\Images\ImageMissingDerivativesController`'s
 * own cursor-paginated scan, one real caller.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * `new SrcImage($row)` accepts `array<string, mixed>` (not this DTO), so
 * the caller calls `toArray()` before constructing one, same
 * boundary-unwrap convention every other Projection in this codebase
 * uses.
 */
final readonly class MissingDerivativeRow
{
    public function __construct(
        public int $id,
        public ?string $path,
        public ?string $representativeExt,
        public ?int $width,
        public ?int $height,
        public ?int $rotation,
    ) {}

    /**
     * @return array{id: int, path: ?string, representative_ext: ?string, width: ?int, height: ?int, rotation: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
        ];
    }
}
