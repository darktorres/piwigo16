<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findPathsAndLevelForIds()}'s own
 * row shape (same 3 columns as {@see PathRepresentativeExt}, plus
 * `level`) -- {@see \Piwigo\Ws\Categories::getList()}'s real (and
 * only) consumer, its "does the viewer's privacy level allow this
 * thumbnail" check.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\Image\DerivativeImage::url()} accepts
 * `array<string, mixed>|SrcImage` (not this DTO), so the caller calls
 * `toArray()` before handing a row to it, same boundary-unwrap
 * convention every other Projection in this codebase uses.
 */
final readonly class PathRepresentativeExtLevel
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $representativeExt,
        public int $level,
    ) {}

    /**
     * @return array{id: int, path: string, representative_ext: ?string, level: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
            'level' => $this->level,
        ];
    }
}
