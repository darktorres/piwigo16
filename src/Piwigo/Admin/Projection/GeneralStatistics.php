<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * {@see \Piwigo\Admin\InstallationStats::getGeneralStatistics()}'s own
 * fixed result shape.
 */
final readonly class GeneralStatistics
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
        public int $diskUsage,
        public int $nbFormats,
        public int $formatsDiskUsage,
    ) {}

    /**
     * {@see \Piwigo\Admin\PiwigoInfosSender}'s own external telemetry
     * payload splices more keys onto this shape after the fact -- unbox
     * to array at that mutation boundary.
     *
     * @return array{nb_photos: int, nb_categories: int, nb_tags: int,
     *   nb_image_tag: int, nb_users: int, nb_admins: int, nb_groups: int,
     *   nb_rates: int, nb_views: int, disk_usage: int, nb_formats: int,
     *   formats_disk_usage: int}
     */
    public function toArray(): array
    {
        return [
            'nb_photos' => $this->nbPhotos,
            'nb_categories' => $this->nbCategories,
            'nb_tags' => $this->nbTags,
            'nb_image_tag' => $this->nbImageTag,
            'nb_users' => $this->nbUsers,
            'nb_admins' => $this->nbAdmins,
            'nb_groups' => $this->nbGroups,
            'nb_rates' => $this->nbRates,
            'nb_views' => $this->nbViews,
            'disk_usage' => $this->diskUsage,
            'nb_formats' => $this->nbFormats,
            'formats_disk_usage' => $this->formatsDiskUsage,
        ];
    }
}
