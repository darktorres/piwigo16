<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Item 15 Sub-item D: {@see ImageRepository::existsWithColumnValue()}'s
 * `$column` parameter, enumerated -- {@see \Piwigo\Ws\PwgImages}'s own
 * upload-uniqueness check already bounds it to exactly these 2 values via
 * a `match()` on `CurrentConfig::uniquenessMode()` before it ever reaches
 * this method; confirmed via a fresh grep before converting.
 */
enum ImageUniquenessColumn
{
    case Md5sum;
    case File;
}
