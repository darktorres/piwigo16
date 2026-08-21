<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Controller\Projection\IndexView;

function makeIndexView(bool $monthCalendarActive): IndexView
{
    return new IndexView(
        thumbNavbar: [],
        title: '',
        nbItems: 0,
        uModeNormal: null,
        uModeFlat: null,
        uModeCreated: null,
        uModePosted: null,
        searchInSetButton: null,
        searchInSetAction: null,
        searchInSetUrl: null,
        combinableTags: null,
        uEdit: null,
        uCaddie: null,
        categorySearchResults: null,
        noSearchResults: null,
        imageOrders: null,
        contentDescription: null,
        uSlideshow: null,
        relatedTagsAction: null,
        relatedTags: null,
        tagSearchResults: [],
        imageDerivatives: [],
        selectedTagsTemplate: new Html(''),
        pluginIndexButtons: [],
        searchId: null,
        monthCalendarActive: $monthCalendarActive,
    );
}

test('pageAssets skips month_calendar.css when no chronology calendar view applies', function (): void {
    $view = makeIndexView(monthCalendarActive: false);

    $ids = array_map(static fn ($asset) => $asset->id, $view->pageAssets());

    expect($ids)
        ->not->toContain('month_calendar');
});

test('pageAssets registers month_calendar.css when the chronology calendar view applies', function (): void {
    $view = makeIndexView(monthCalendarActive: true);

    $ids = array_map(static fn ($asset) => $asset->id, $view->pageAssets());

    expect($ids)
        ->toContain('month_calendar');
});
