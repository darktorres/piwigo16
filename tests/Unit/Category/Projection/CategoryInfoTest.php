<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryInfo;

test('toArray maps every property to its snake_case wire key', function (): void {
    $info = new CategoryInfo(
        id: 5,
        name: 'Holidays',
        idUppercat: 2,
        comment: 'Summer trip',
        dir: 'holidays',
        rank: 1,
        status: 'public',
        siteId: 1,
        visible: true,
        representativePictureId: 42,
        uppercats: '1,2,5',
        commentable: true,
        globalRank: '01.02.01',
        imageOrder: 'name ASC',
        permalink: 'holidays',
        lastmodified: '2026-08-01 12:00:00',
        upperNames: [
            [
                'id' => 1,
                'name' => 'Root',
                'permalink' => null,
            ],
            [
                'id' => 2,
                'name' => 'Trips',
                'permalink' => 'trips',
            ],
        ],
    );

    expect($info->toArray())
        ->toBe([
            'id' => 5,
            'name' => 'Holidays',
            'id_uppercat' => 2,
            'comment' => 'Summer trip',
            'dir' => 'holidays',
            'rank' => 1,
            'status' => 'public',
            'site_id' => 1,
            'visible' => true,
            'representative_picture_id' => 42,
            'uppercats' => '1,2,5',
            'commentable' => true,
            'global_rank' => '01.02.01',
            'image_order' => 'name ASC',
            'permalink' => 'holidays',
            'lastmodified' => '2026-08-01 12:00:00',
            'upper_names' => [
                [
                    'id' => 1,
                    'name' => 'Root',
                    'permalink' => null,
                ],
                [
                    'id' => 2,
                    'name' => 'Trips',
                    'permalink' => 'trips',
                ],
            ],
        ]);
});

test('toArray round-trips a root category (nullable fields left null)', function (): void {
    $info = new CategoryInfo(
        id: 1,
        name: 'Root',
        idUppercat: null,
        comment: null,
        dir: null,
        rank: null,
        status: 'public',
        siteId: null,
        visible: true,
        representativePictureId: null,
        uppercats: '1',
        commentable: false,
        globalRank: null,
        imageOrder: null,
        permalink: null,
        lastmodified: '2026-08-01 12:00:00',
        upperNames: [],
    );

    expect($info->toArray())
        ->toMatchArray([
            'id_uppercat' => null,
            'comment' => null,
            'dir' => null,
            'rank' => null,
            'site_id' => null,
            'representative_picture_id' => null,
            'global_rank' => null,
            'image_order' => null,
            'permalink' => null,
            'upper_names' => [],
        ]);
});
