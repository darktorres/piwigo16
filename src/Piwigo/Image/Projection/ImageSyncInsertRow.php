<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::massInsertImages()}'s own
 * `images` insert row -- {@see \Piwigo\Controller\Admin\
 * SiteUpdateSubController}'s own filesystem-sync "add every
 * newly-discovered photo at once" step, its real (and only) caller.
 * `$id` is self-assigned by that caller before the insert (used to
 * pre-link categories/formats in the same sync pass); `$level` is
 * genuinely optional there (only included when the site-wide privacy
 * level override is active). A different shape from {@see ImageInsertRow}
 * (the single-upload path) by design -- sync discovers photos on disk
 * rather than receiving them from a validated upload request, so it
 * never has filesize/width/height/md5sum/rotation at insert time.
 */
final readonly class ImageSyncInsertRow
{
    public function __construct(
        public int $id,
        public string $file,
        public string $name,
        public string $dateAvailable,
        public string $path,
        public ?string $representativeExt,
        public int $storageCategoryId,
        public int $addedBy,
        public ?int $level = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'file' => $this->file,
            'name' => $this->name,
            'date_available' => $this->dateAvailable,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
            'storage_category_id' => $this->storageCategoryId,
            'added_by' => $this->addedBy,
        ];

        if ($this->level !== null) {
            $result['level'] = $this->level;
        }

        return $result;
    }
}
