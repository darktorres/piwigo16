<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::findImageThumbInfoByIds()}'s own row
 * shape -- {@see \Piwigo\Admin\RatingUserPageRenderer}'s real (and only)
 * consumer, the admin "Rating by user" report's thumbnail lookup.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\Image\DerivativeImage::url()} accepts
 * `array<string, mixed>|SrcImage` (not this DTO), so the report renderer
 * calls `toArray()` before handing a row to it, same boundary-unwrap
 * convention every other Projection in this codebase uses.
 */
final readonly class ImageThumbInfo
{
    public function __construct(
        public int $id,
        public ?string $name,
        public string $file,
        public string $path,
        public ?string $representativeExt,
        public int $level,
    ) {}

    /**
     * @return array{id: int, name: ?string, file: string, path: string, representative_ext: ?string, level: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file' => $this->file,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
            'level' => $this->level,
        ];
    }
}
