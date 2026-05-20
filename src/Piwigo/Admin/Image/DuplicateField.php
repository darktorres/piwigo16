<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

/**
 * Batch-manager "find duplicates" criteria — drives which DB columns
 * are grouped on by `ImageRepository::findIdsInDuplicateGroups()`.
 * `Dimensions` covers both width and height in one criterion, so the
 * column mapping is many-to-one.
 */
enum DuplicateField: string
{
    case Filename   = 'filename';
    case Checksum   = 'checksum';
    case Date       = 'date';
    case Dimensions = 'dimensions';

    /** @return list<string> */
    public function dbColumns(): array
    {
        return match ($this) {
            self::Filename   => ['file'],
            self::Checksum   => ['md5sum'],
            self::Date       => ['date_creation'],
            self::Dimensions => ['width', 'height'],
        };
    }
}
