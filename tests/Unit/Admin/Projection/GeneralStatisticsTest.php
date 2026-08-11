<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\GeneralStatistics;

test('toArray maps every property to its snake_case wire key', function (): void {
    $stats = new GeneralStatistics(
        nbPhotos: 1,
        nbCategories: 2,
        nbTags: 3,
        nbImageTag: 4,
        nbUsers: 5,
        nbAdmins: 6,
        nbGroups: 7,
        nbRates: 8,
        nbViews: 9,
        diskUsage: 10,
        nbFormats: 11,
        formatsDiskUsage: 12,
    );

    expect($stats->toArray())
        ->toBe([
            'nb_photos' => 1,
            'nb_categories' => 2,
            'nb_tags' => 3,
            'nb_image_tag' => 4,
            'nb_users' => 5,
            'nb_admins' => 6,
            'nb_groups' => 7,
            'nb_rates' => 8,
            'nb_views' => 9,
            'disk_usage' => 10,
            'nb_formats' => 11,
            'formats_disk_usage' => 12,
        ]);
});
