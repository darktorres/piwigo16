<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findUploadResultInfoById()}'s own
 * row shape -- `Ws\Images::upload()`'s own "what does the
 * just-uploaded/replaced photo look like" lookup, used to build the
 * response's thumbnail URLs.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\Image\DerivativeImage::thumbUrl()}/`url()` accept
 * `array<string, mixed>|SrcImage` (not this DTO), so the caller calls
 * `toArray()` before handing a row to them, same boundary-unwrap
 * convention every other Projection in this codebase uses.
 */
final readonly class UploadResultInfo
{
    public function __construct(
        public int $id,
        public ?string $name,
        public ?string $representativeExt,
        public string $path,
    ) {}

    /**
     * @return array{id: int, name: ?string, representative_ext: ?string, path: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'representative_ext' => $this->representativeExt,
            'path' => $this->path,
        ];
    }
}
