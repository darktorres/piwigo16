<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::insertImage()}'s own `images`
 * insert row -- {@see \Piwigo\Admin\Upload\UploadService::
 * addUploadedFile()}'s "brand-new photo" branch, its real (and only)
 * caller. `$level`/`$representativeExt` are genuinely optional there
 * (only included when the caller actually has a value), everything
 * else is always present.
 */
final readonly class ImageInsertRow
{
    public function __construct(
        public string $file,
        public string $name,
        public string $dateAvailable,
        public string $lastmodified,
        public string $path,
        public float $filesize,
        public ?int $width,
        public ?int $height,
        public string $md5sum,
        public int $addedBy,
        public int $rotation,
        public ?int $level = null,
        public ?string $representativeExt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'file' => $this->file,
            'name' => $this->name,
            'date_available' => $this->dateAvailable,
            'lastmodified' => $this->lastmodified,
            'path' => $this->path,
            'filesize' => $this->filesize,
            'width' => $this->width,
            'height' => $this->height,
            'md5sum' => $this->md5sum,
            'added_by' => $this->addedBy,
            'rotation' => $this->rotation,
        ];

        if ($this->level !== null) {
            $result['level'] = $this->level;
        }

        if ($this->representativeExt !== null) {
            $result['representative_ext'] = $this->representativeExt;
        }

        return $result;
    }
}
