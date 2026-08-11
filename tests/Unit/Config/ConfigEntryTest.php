<?php

declare(strict_types=1);

use Piwigo\Config\ConfigEntry;

test('constructs with param, value, and comment', function (): void {
    $entry = new ConfigEntry('gallery_title', 'My Gallery', 'the gallery title');

    expect($entry->param)
        ->toBe('gallery_title')
        ->and($entry->value)
        ->toBe('My Gallery')
        ->and($entry->comment)
        ->toBe('the gallery title');
});

test('value and comment default to null', function (): void {
    $entry = new ConfigEntry('c13y_ignore');

    expect($entry->value)
        ->toBeNull()
        ->and($entry->comment)
        ->toBeNull();
});
