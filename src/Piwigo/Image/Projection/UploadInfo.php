<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findUploadInfoById()}'s own row
 * shape -- `Ws\Images::addFile()`'s "what's the current state of this
 * image, before we merge in a bigger chunked upload" lookup.
 *
 * `toArray()` exists for that one caller's own dynamic `foreach (['width',
 * 'height', 'filesize'] as $key) { ... $image[$key] ... }` comparison loop
 * -- a real, genuinely dynamic-key read this DTO's typed properties can't
 * satisfy directly.
 */
final readonly class UploadInfo
{
    public function __construct(
        public string $path,
        public string $file,
        public ?string $md5sum,
        public ?int $width,
        public ?int $height,
        public ?int $filesize,
    ) {}

    /**
     * @return array{path: string, file: string, md5sum: ?string, width: ?int, height: ?int, filesize: ?int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'file' => $this->file,
            'md5sum' => $this->md5sum,
            'width' => $this->width,
            'height' => $this->height,
            'filesize' => $this->filesize,
        ];
    }
}
