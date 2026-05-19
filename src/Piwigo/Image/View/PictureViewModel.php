<?php

declare(strict_types=1);

namespace Piwigo\Image\View;

use Piwigo\Core\StringUtil;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\Entity\Image;
use Piwigo\Image\SrcImage;

/**
 * View model for the single-image page and category index thumbnails.
 *
 * Holds an `Image` entity plus the derived presentation fields that the
 * picture/category templates expect (`src_image`, `derivatives`,
 * `path_ext`, `file_ext`, urls, title). The `toArray()` method produces
 * the row-shape Latte template still consumes; in-PHP code reads typed
 * properties.
 *
 * Replaces the historical `$row['src_image'] = …; $row['derivatives'] = …;
 * $row['path_ext'] = …` accumulation pattern that mutated DB-row arrays
 * in `PictureController`, `CategoryCatsRenderer`, and `CategoryDefaultRenderer`.
 */
final readonly class PictureViewModel
{
    /**
     * @param array<DerivativeImage> $derivatives
     */
    public function __construct(
        public Image $image,
        public SrcImage $srcImage,
        public array $derivatives,
        public string $pathExt,
        public string $fileExt,
        public string $url = '',
        public string $title = '',
        public string $titleEsc = '',
        public ?string $elementPath = null,
        public ?string $elementUrl = null,
        public ?string $downloadUrl = null,
    ) {
    }

    /**
     * Build a view-model with `src_image` + `derivatives` populated from
     * the image. Picture/category-page-specific fields (urls, title) are
     * left empty for the caller to fill via {@see withUrls()} /
     * {@see withTitle()}.
     */
    public static function fromImage(Image $image): self
    {
        $srcImage = SrcImage::fromImage($image);
        return new self(
            image:       $image,
            srcImage:    $srcImage,
            derivatives: DerivativeImage::getAll($srcImage),
            pathExt:     strtolower(StringUtil::getExtension($image->path->value)),
            fileExt:     strtolower(StringUtil::getExtension($image->file->value)),
        );
    }

    public function withUrl(string $url): self
    {
        return new self(
            image:       $this->image,
            srcImage:    $this->srcImage,
            derivatives: $this->derivatives,
            pathExt:     $this->pathExt,
            fileExt:     $this->fileExt,
            url:         $url,
            title:       $this->title,
            titleEsc:    $this->titleEsc,
            elementPath: $this->elementPath,
            elementUrl:  $this->elementUrl,
            downloadUrl: $this->downloadUrl,
        );
    }

    public function withTitle(string $title, string $titleEsc): self
    {
        return new self(
            image:       $this->image,
            srcImage:    $this->srcImage,
            derivatives: $this->derivatives,
            pathExt:     $this->pathExt,
            fileExt:     $this->fileExt,
            url:         $this->url,
            title:       $title,
            titleEsc:    $titleEsc,
            elementPath: $this->elementPath,
            elementUrl:  $this->elementUrl,
            downloadUrl: $this->downloadUrl,
        );
    }

    public function withCurrentExtras(string $elementPath, ?string $elementUrl, ?string $downloadUrl): self
    {
        return new self(
            image:       $this->image,
            srcImage:    $this->srcImage,
            derivatives: $this->derivatives,
            pathExt:     $this->pathExt,
            fileExt:     $this->fileExt,
            url:         $this->url,
            title:       $this->title,
            titleEsc:    $this->titleEsc,
            elementPath: $elementPath,
            elementUrl:  $elementUrl,
            downloadUrl: $downloadUrl,
        );
    }

    /**
     * Produce the row-shaped array the Latte templates and legacy events
     * (`PicturePicturesData`, `GetElementMetadataAvailable`, …) still
     * consume. Mirrors the historical `findByIds`-row shape plus the
     * derived view fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $img = $this->image;
        return [
            'id'                   => $img->id->value,
            'file'                 => $img->file->value,
            'path'                 => $img->path->value,
            'name'                 => $img->name,
            'comment'              => $img->comment,
            'author'               => $img->author,
            'hit'                  => $img->hit,
            'filesize'             => $img->filesize,
            'width'                => $img->width,
            'height'               => $img->height,
            'representative_ext'   => $img->representativeExt,
            'date_available'       => $img->dateAvailable?->value,
            'date_creation'        => $img->dateCreation?->value,
            'date_metadata_update' => $img->dateMetadataUpdate?->value,
            'rating_score'         => $img->ratingScore,
            'storage_category_id'  => $img->storageCategoryId?->value,
            'level'                => $img->level,
            'md5sum'               => $img->md5sum?->value,
            'added_by'             => $img->addedBy?->value,
            'rotation'             => $img->rotation,
            'latitude'             => $img->latitude,
            'longitude'            => $img->longitude,
            'coi'                  => $img->coi,
            'src_image'            => $this->srcImage,
            'derivatives'          => $this->derivatives,
            'path_ext'             => $this->pathExt,
            'file_ext'             => $this->fileExt,
            'url'                  => $this->url,
            'TITLE'                => $this->title,
            'TITLE_ESC'            => $this->titleEsc,
            'element_path'         => $this->elementPath,
            'element_url'          => $this->elementUrl,
            'download_url'         => $this->downloadUrl,
        ];
    }
}
