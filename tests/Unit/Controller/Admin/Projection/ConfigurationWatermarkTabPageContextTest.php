<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationWatermarkTabPageContext;

test('toArray flattens watermark_files, and omits watermark when null', function (): void {
    $context = new ConfigurationWatermarkTabPageContext(
        watermarkFiles: [
            '' => '---',
            'logo.png' => 'logo.png',
        ],
        watermark: null,
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKey('watermark')
        ->and($result['watermark_files'])->toBe([
            '' => '---',
            'logo.png' => 'logo.png',
        ]);
});

test('toArray includes watermark when set', function (): void {
    $context = new ConfigurationWatermarkTabPageContext(
        watermarkFiles: [],
        watermark: [
            'file' => 'logo.png',
            'position' => 'topleft',
        ],
    );

    expect($context->toArray()['watermark'])->toBe([
        'file' => 'logo.png',
        'position' => 'topleft',
    ]);
});
