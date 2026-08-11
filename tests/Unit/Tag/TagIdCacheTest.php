<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\TagIdCache;

test('get returns null for a tag name that was never set', function (): void {
    $cache = new TagIdCache();

    expect($cache->get('landscape'))
        ->toBeNull();
});

test('set then get returns the exact id for that tag name', function (): void {
    $cache = new TagIdCache();

    $cache->set('landscape', TagId::from(42));

    expect($cache->get('landscape'))
        ->toEqual(TagId::from(42));
});

test('a second, never-set tag name still misses after another tag was set', function (): void {
    $cache = new TagIdCache();

    $cache->set('landscape', TagId::from(42));

    expect($cache->get('portrait'))
        ->toBeNull();
});

test('distinct tag names keep distinct ids without cross-contamination', function (): void {
    $cache = new TagIdCache();

    $cache->set('landscape', TagId::from(42));
    $cache->set('portrait', TagId::from(17));

    expect($cache->get('landscape'))
        ->toEqual(TagId::from(42))
        ->and($cache->get('portrait'))
        ->toEqual(TagId::from(17));
});

test('set overwrites a previously cached id for the same tag name', function (): void {
    $cache = new TagIdCache();

    $cache->set('landscape', TagId::from(42));
    $cache->set('landscape', TagId::from(99));

    expect($cache->get('landscape'))
        ->toEqual(TagId::from(99));
});
