<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Item 15 Sub-item D: {@see ImageRepository::findIdsGroupedByDuplicateFields()}'s
 * `$fields` list, enumerated -- {@see \Piwigo\Admin\BatchManager\FilterResolver::
 * duplicateFieldsFromFilter()}'s own fixed internal allowlist (file/md5sum/
 * date_creation/width+height), confirmed via a fresh grep before converting.
 * A caller passes any subset of these cases (the "dimensions" toggle adds
 * both Width and Height together).
 */
enum ImageDuplicateField
{
    case File;
    case Md5sum;
    case DateCreation;
    case Width;
    case Height;

    /**
     * The raw `images` column name -- needed where a caller mixes this
     * enum's cases back into a trusted, caller-composed raw ORDER BY
     * string (e.g. {@see \Piwigo\Admin\BatchManagerGlobalPageRenderer}'s
     * duplicates-mode thumbnail ordering, which also splices in
     * `CurrentConfig::orderBy()`'s own free-form text -- see
     * {@see \Piwigo\Image\PhotoSortField}'s own docblock for why that
     * wider ORDER BY string itself deliberately stays untyped).
     */
    public function column(): string
    {
        return match ($this) {
            self::File => 'file',
            self::Md5sum => 'md5sum',
            self::DateCreation => 'date_creation',
            self::Width => 'width',
            self::Height => 'height',
        };
    }
}
