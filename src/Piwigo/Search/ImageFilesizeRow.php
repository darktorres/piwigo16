<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (id, filesize) row from SearchRepository::findFilesizesForFilter(). */
final readonly class ImageFilesizeRow
{
    public function __construct(
        public int      $id,
        public int|null $filesize,
    ) {
    }
}
