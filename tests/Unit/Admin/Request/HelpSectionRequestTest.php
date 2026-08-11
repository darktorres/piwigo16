<?php

declare(strict_types=1);

use Piwigo\Admin\Request\HelpSectionRequest;

test('fromArray returns the requested section', function (): void {
    $request = HelpSectionRequest::fromArray([
        'section' => 'faq',
    ]);

    expect($request->section)
        ->toBe('faq');
});

test('fromArray defaults to add_photos when the section param is missing', function (): void {
    $request = HelpSectionRequest::fromArray([]);

    expect($request->section)
        ->toBe('add_photos');
});

test('fromArray defaults to add_photos when the section param is not a string', function (): void {
    $request = HelpSectionRequest::fromArray([
        'section' => [
            'nested' => 'array',
        ],
    ]);

    expect($request->section)
        ->toBe('add_photos');
});
