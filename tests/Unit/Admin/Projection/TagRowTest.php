<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TagRow;

test('jsonSerialize emits the snake_case keys tags.ts reads, in the order the page has always emitted them', function (): void {
    $row = new TagRow(
        id: 7,
        name: 'Nature',
        rawName: 'nature',
        urlName: 'nature',
        counter: 12,
        altNames: 'flora, fauna',
    );

    expect($row->jsonSerialize())
        ->toBe([
            'name' => 'Nature',
            'id' => 7,
            'url_name' => 'nature',
            'raw_name' => 'nature',
            'counter' => 12,
            'alt_names' => 'flora, fauna',
        ]);
});

test('a tag with no photos omits counter entirely rather than sending 0', function (): void {
    $row = new TagRow(
        id: 3,
        name: 'travel',
        rawName: 'travel',
        urlName: 'travel',
        counter: 0,
        altNames: null,
    );

    // `themes/admin/default/js/tags.ts` declares `counter?: number` and
    // `alt_names?: string`: absent is the shape it expects, and the two are
    // independent -- a tag can have alt names and no photos.
    expect($row->jsonSerialize())
        ->toBe([
            'name' => 'travel',
            'id' => 3,
            'url_name' => 'travel',
            'raw_name' => 'travel',
        ]);
});

test('alt names survive an empty counter', function (): void {
    $row = new TagRow(
        id: 4,
        name: 'family',
        rawName: 'family',
        urlName: 'family',
        counter: 0,
        altNames: 'kin',
    );

    expect($row->jsonSerialize())
        ->toBe([
            'name' => 'family',
            'id' => 4,
            'url_name' => 'family',
            'raw_name' => 'family',
            'alt_names' => 'kin',
        ]);
});

test('json_encode goes through jsonSerialize rather than the property names', function (): void {
    $row = new TagRow(
        id: 1,
        name: 'nature',
        rawName: 'nature',
        urlName: 'nature',
        counter: 3,
        altNames: null,
    );

    // The template hands the whole list to tags.ts as `data-tags`, so the
    // encoded form -- not the property names -- is the contract.
    expect(json_encode([$row]))
        ->toBe('[{"name":"nature","id":1,"url_name":"nature","raw_name":"nature","counter":3}]');
});
