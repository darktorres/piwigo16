<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/** Typed snapshot of site-wide counts returned by AdminService::getPwgGeneralStatitics(). */
final readonly class AdminStats
{
    public function __construct(
        public int $nbPhotos,
        public int $nbCategories,
        public int $nbTags,
        public int $nbImageTag,
        public int $nbUsers,
        public int $nbAdmins,
        public int $nbGroups,
        public int $nbRates,
        public int $nbViews,
        /** Raw sum of image filesizes in KB (as stored in the DB). */
        public int $diskUsage,
        public int $nbFormats,
        public int $formatsDiskUsage,
    ) {
    }
}
