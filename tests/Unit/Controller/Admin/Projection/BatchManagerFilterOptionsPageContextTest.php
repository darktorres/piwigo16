<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\DimensionBounds;
use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeBounds;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Admin\BatchManager\Projection\RatioRange;
use Piwigo\Controller\Admin\Projection\BatchManagerFilterOptionsPageContext;

test('toArray passes both value objects through without flattening', function (): void {
    $context = new BatchManagerFilterOptionsPageContext(
        dimensions: new DimensionFilterOptions(
            widths: '600,1920',
            heights: '480,1080',
            ratios: '1.25,1.78',
            bounds: new DimensionBounds(
                minWidth: 600,
                maxWidth: 1920,
                minHeight: 480,
                maxHeight: 1080,
                minRatio: 1.25,
                maxRatio: 1.78,
            ),
            ratioPortrait: null,
            ratioSquare: null,
            ratioLandscape: new RatioRange(min: 1.25, max: 1.78),
            ratioPanorama: null,
            selected: new DimensionBounds(
                minWidth: 600,
                maxWidth: 1920,
                minHeight: 480,
                maxHeight: 1080,
                minRatio: 1.25,
                maxRatio: 1.78,
            ),
        ),
        filesize: new FilesizeFilterOptions(
            list: '0.0,5.0',
            bounds: new FilesizeBounds(min: '0.0', max: '5.0'),
            selected: new FilesizeBounds(min: '0.0', max: '5.0'),
        ),
    );

    // The context is a pass-through as of P58-A: batch_manager_filter.inc.latte
    // reads these as objects. The page-data flatten each one still carries is
    // asserted by its own toPageData() test below.
    expect($context->toArray())
        ->toBe([
            'dimensions' => $context->dimensions,
            'filesize' => $context->filesize,
        ]);
});

test('toPageData keeps the JSON payload batchManagerFilter.ts reads, omitting absent ratio categories', function (): void {
    $dimensions = new DimensionFilterOptions(
        widths: '600,1920',
        heights: '480,1080',
        ratios: '1.25,1.78',
        bounds: new DimensionBounds(
            minWidth: 600,
            maxWidth: 1920,
            minHeight: 480,
            maxHeight: 1080,
            minRatio: 1.25,
            maxRatio: 1.78,
        ),
        ratioPortrait: null,
        ratioSquare: null,
        ratioLandscape: new RatioRange(min: 1.25, max: 1.78),
        ratioPanorama: null,
        selected: new DimensionBounds(
            minWidth: 600,
            maxWidth: 1920,
            minHeight: 480,
            maxHeight: 1080,
            minRatio: 1.25,
            maxRatio: 1.78,
        ),
    );

    // Key order matters as much as the keys: this is what json_encode()
    // emits into the page, and the three absent ratio buckets are absent
    // rather than null -- toBe() is order-sensitive on both counts.
    expect($dimensions->toPageData())
        ->toBe([
            'widths' => '600,1920',
            'heights' => '480,1080',
            'ratios' => '1.25,1.78',
            'bounds' => [
                'min_width' => 600,
                'max_width' => 1920,
                'min_height' => 480,
                'max_height' => 1080,
                'min_ratio' => 1.25,
                'max_ratio' => 1.78,
            ],
            'ratio_landscape' => [
                'min' => 1.25,
                'max' => 1.78,
            ],
            'selected' => [
                'min_width' => 600,
                'max_width' => 1920,
                'min_height' => 480,
                'max_height' => 1080,
                'min_ratio' => 1.25,
                'max_ratio' => 1.78,
            ],
        ]);

    expect(new FilesizeFilterOptions(
        list: '0.0,5.0',
        bounds: new FilesizeBounds(min: '0.0', max: '5.0'),
        selected: new FilesizeBounds(min: '0.0', max: '5.0'),
    )->toPageData())
        ->toBe([
            'list' => '0.0,5.0',
            'bounds' => [
                'min' => '0.0',
                'max' => '5.0',
            ],
            'selected' => [
                'min' => '0.0',
                'max' => '5.0',
            ],
        ]);
});
