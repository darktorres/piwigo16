<?php

declare(strict_types=1);

use Piwigo\Rate\Projection\RatingReportRow;

/**
 * @return array{id: int, path: string, file: string, representativeExt: ?string, score: ?float, recentlyRated: ?string, avgRates: ?float, nbRates: int, sumRates: float}
 */
function fullRatingReportRowArgs(): array
{
    return [
        'id' => 1,
        'path' => 'upload/2026/08/01/photo.jpg',
        'file' => 'photo.jpg',
        'representativeExt' => 'jpg',
        'score' => 4.5,
        'recentlyRated' => '2026-08-01 12:00:00',
        'avgRates' => 4.5,
        'nbRates' => 2,
        'sumRates' => 9.0,
    ];
}

test('constructs with distinct values for every property', function (): void {
    $row = new RatingReportRow(...fullRatingReportRowArgs());

    expect($row->id)->toBe(1)
        ->and($row->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($row->file)->toBe('photo.jpg')
        ->and($row->representativeExt)->toBe('jpg')
        ->and($row->score)->toBe(4.5)
        ->and($row->recentlyRated)->toBe('2026-08-01 12:00:00')
        ->and($row->avgRates)->toBe(4.5)
        ->and($row->nbRates)->toBe(2)
        ->and($row->sumRates)->toBe(9.0);
});

test('toArray round-trips the exact same shape the constructor accepted', function (): void {
    $roundTripped = new RatingReportRow(...fullRatingReportRowArgs())->toArray();

    expect($roundTripped)->toBe([
        'id' => 1,
        'path' => 'upload/2026/08/01/photo.jpg',
        'file' => 'photo.jpg',
        'representative_ext' => 'jpg',
        'score' => 4.5,
        'recently_rated' => '2026-08-01 12:00:00',
        'avg_rates' => 4.5,
        'nb_rates' => 2,
        'sum_rates' => 9.0,
    ]);
});
