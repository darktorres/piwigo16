<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\MetadataPanel;
use Piwigo\Picture\Projection\PictureMetadataPageContext;

test('toArray omits metadata entirely when null', function (): void {
    expect(new PictureMetadataPageContext(null)->toArray())
        ->toBe([]);
});

test('toArray includes metadata when set', function (): void {
    $metadata = [
        new MetadataPanel(title: 'EXIF Metadata', lines: [
            'Model' => 'Canon EOS 5D',
        ]),
        new MetadataPanel(title: 'IPTC Metadata', lines: [
            'Caption' => 'A sunset',
        ]),
    ];

    expect(new PictureMetadataPageContext($metadata)->toArray())
        ->toBe([
            'metadata' => [
                [
                    'TITLE' => 'EXIF Metadata',
                    'lines' => [
                        'Model' => 'Canon EOS 5D',
                    ],
                ],
                [
                    'TITLE' => 'IPTC Metadata',
                    'lines' => [
                        'Caption' => 'A sunset',
                    ],
                ],
            ],
        ]);
});
