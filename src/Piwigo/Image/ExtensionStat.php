<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** Per-extension file count and total filesize, used in the storage breakdown report. */
final readonly class ExtensionStat
{
    public function __construct(
        public int $extCounter,
        public int $filesize,
    ) {
    }
}
