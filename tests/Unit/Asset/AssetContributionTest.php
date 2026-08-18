<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\AssetKind;
use Piwigo\Asset\LoadMode;

test('script() defaults load mode to Header, no deps, version 0', function (): void {
    $contribution = AssetContribution::script('core.scripts', 'themes/default/js/scripts.js');

    expect($contribution->id)
        ->toBe('core.scripts')
        ->and($contribution->kind)
        ->toBe(AssetKind::Script)
        ->and($contribution->path)
        ->toBe('themes/default/js/scripts.js')
        ->and($contribution->loadMode)
        ->toBe(LoadMode::Header)
        ->and($contribution->dependsOn)
        ->toBe([])
        ->and($contribution->version)
        ->toBe('0')
        ->and($contribution->order)
        ->toBe(0);
});

test('script() carries explicit load mode and dependencies', function (): void {
    $contribution = AssetContribution::script(
        'rating',
        'themes/default/js/rating.js',
        loadMode: LoadMode::Async,
        dependsOn: ['core.scripts'],
    );

    expect($contribution->loadMode)
        ->toBe(LoadMode::Async)
        ->and($contribution->dependsOn)
        ->toBe(['core.scripts']);
});

test('css() defaults id to md5(path) and order to 0', function (): void {
    $contribution = AssetContribution::css('themes/default/print.css');

    expect($contribution->id)
        ->toBe(md5('themes/default/print.css'))
        ->and($contribution->kind)
        ->toBe(AssetKind::Css)
        ->and($contribution->order)
        ->toBe(0)
        ->and($contribution->loadMode)
        ->toBeNull()
        ->and($contribution->dependsOn)
        ->toBe([]);
});

test('css() carries an explicit id and order', function (): void {
    $contribution = AssetContribution::css('themes/default/print.css', id: 'print', order: -10);

    expect($contribution->id)
        ->toBe('print')
        ->and($contribution->order)
        ->toBe(-10);
});
