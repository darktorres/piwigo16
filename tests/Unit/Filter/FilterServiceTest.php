<?php

declare(strict_types=1);

use Piwigo\Filter\FilterService;

beforeEach(function (): void {
    $GLOBALS['filter'] = [];
});

test('updateCatsWithFilteredData leaves cats untouched when the filter is disabled', function (): void {
    $GLOBALS['filter'] = ['enabled' => false, 'categories' => [1 => ['nb_images' => 999]]];
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => 1, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData leaves cats untouched when filter categories is not an array', function (): void {
    $GLOBALS['filter'] = ['enabled' => true, 'categories' => 'not-an-array'];
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => 1, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData overwrites the aggregate fields for a matched category id', function (): void {
    $GLOBALS['filter'] = [
        'enabled' => true,
        'categories' => [
            1 => [
                'date_last' => '2026-01-01',
                'max_date_last' => '2026-01-02',
                'count_images' => 10,
                'count_categories' => 2,
                'nb_images' => 20,
            ],
        ],
    ];
    $cats = [0 => ['id' => 1, 'nb_images' => 5, 'untouched' => 'kept']];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0])->toBe([
        'id' => 1,
        'nb_images' => 20,
        'untouched' => 'kept',
        'date_last' => '2026-01-01',
        'max_date_last' => '2026-01-02',
        'count_images' => 10,
        'count_categories' => 2,
    ]);
});

test('updateCatsWithFilteredData skips a category id with no matching filter entry', function (): void {
    $GLOBALS['filter'] = ['enabled' => true, 'categories' => [2 => ['nb_images' => 20]]];
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => 1, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData skips a category row with a non-int/string id', function (): void {
    $GLOBALS['filter'] = ['enabled' => true, 'categories' => [1 => ['nb_images' => 20]]];
    $cats = [0 => ['id' => null, 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => null, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData matches a string category id', function (): void {
    $GLOBALS['filter'] = ['enabled' => true, 'categories' => ['abc' => ['nb_images' => 30]]];
    $cats = [0 => ['id' => 'abc', 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0]['nb_images'])->toBe(30);
});

test('updateCatsWithFilteredData fills a missing aggregate field with null', function (): void {
    $GLOBALS['filter'] = ['enabled' => true, 'categories' => [1 => ['nb_images' => 20]]];
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService();

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0]['date_last'])->toBeNull()
        ->and($cats[0]['nb_images'])->toBe(20);
});
