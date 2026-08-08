<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findPathsForFileDeletion()}'s own
 * row shape -- {@see \Piwigo\Image\ImageService::deleteElementFiles()}'s
 * real (and only) consumer.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\Image\ImagePathHelper::getElementPath()} accepts
 * `array<string, mixed>` (not this DTO) -- a documented cross-domain
 * generic-row-reader, only ever reading `path` (in scope for a future
 * elimination pass, not this one) -- so the caller calls `toArray()`
 * before handing a row to it, same boundary-unwrap convention every
 * other Projection in this codebase uses.
 */
final readonly class PathRepresentativeExt
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $representativeExt,
    ) {}

    /**
     * @return array{id: int, path: string, representative_ext: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
        ];
    }
}
