<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * {@see ImageRepository::existsWithColumnValue()}'s `$column` parameter,
 * enumerated -- {@see \Piwigo\Controller\Api\Uploads\UploadDuplicatesController}'s
 * own upload-uniqueness check already bounds it to exactly these 2 values
 * via a `match()` on `CurrentConfig::uniquenessMode()` before it ever
 * reaches this method.
 */
enum ImageUniquenessColumn
{
    case Md5sum;
    case File;
}
