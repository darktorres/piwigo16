<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Fully assembled general-stats section of the telemetry payload POSTed to the
 * PEM statistics endpoint.  Combines AdminStats fields with telemetry-specific
 * augmentations (installation date, sync counts, plugin/theme totals).
 */
final readonly class TelemetryGeneralStats
{
    public function __construct(
        // core counts from AdminService::getPwgGeneralStatitics()
        public int     $nbPhotos,
        public int     $nbCategories,
        public int     $nbTags,
        public int     $nbImageTag,
        public int     $nbUsers,
        public int     $nbAdmins,
        public int     $nbGroups,
        public int     $nbRates,
        public int     $nbViews,
        /** Disk usage in KB (converted from the raw AdminStats bytes sum). */
        public int     $diskUsage,
        public int     $nbFormats,
        public int     $formatsDiskUsage,
        // augmented by TelemetryService
        public ?string $installedOn,
        public int     $nbPhotosSynced,
        public ?string $lastPhotoSynced,
        public ?string $lastPhoto,
        public int     $nbPrivatePlugins,
        public int     $nbPlugins,
        public int     $nbPrivateThemes,
        public int     $nbThemes,
        public string  $defaultTheme,
        public string  $defaultLanguage,
        public int     $nbActivities,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nb_photos'          => $this->nbPhotos,
            'nb_categories'      => $this->nbCategories,
            'nb_tags'            => $this->nbTags,
            'nb_image_tag'       => $this->nbImageTag,
            'nb_users'           => $this->nbUsers,
            'nb_admins'          => $this->nbAdmins,
            'nb_groups'          => $this->nbGroups,
            'nb_rates'           => $this->nbRates,
            'nb_views'           => $this->nbViews,
            'disk_usage'         => $this->diskUsage,
            'nb_formats'         => $this->nbFormats,
            'formats_disk_usage' => $this->formatsDiskUsage,
            'installed_on'       => $this->installedOn,
            'nb_photos_synced'   => $this->nbPhotosSynced,
            'last_photo_synced'  => $this->lastPhotoSynced,
            'last_photo'         => $this->lastPhoto,
            'nb_private_plugins' => $this->nbPrivatePlugins,
            'nb_plugins'         => $this->nbPlugins,
            'nb_private_themes'  => $this->nbPrivateThemes,
            'nb_themes'          => $this->nbThemes,
            'default_theme'      => $this->defaultTheme,
            'default_language'   => $this->defaultLanguage,
            'nb_activities'      => $this->nbActivities,
        ];
    }
}
