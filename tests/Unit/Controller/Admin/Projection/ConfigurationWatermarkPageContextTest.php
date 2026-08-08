<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationWatermarkPageContext;

test('toArray omits every key when all fields are null', function (): void {
    $context = new ConfigurationWatermarkPageContext(
        saveSuccess: null,
        watermark: null,
        ferrors: null,
    );

    expect($context->toArray())->toBe([]);
});

test('toArray includes save_success alone on the success branch', function (): void {
    $context = new ConfigurationWatermarkPageContext(
        saveSuccess: 'Your configuration settings are saved',
        watermark: null,
        ferrors: null,
    );

    expect($context->toArray())->toBe([
        'save_success' => 'Your configuration settings are saved',
    ]);
});

test('toArray includes watermark and ferrors together on the error branch', function (): void {
    $context = new ConfigurationWatermarkPageContext(
        saveSuccess: null,
        watermark: ['file' => 'logo.png'],
        ferrors: ['watermark' => ['xpos' => '[0..100]']],
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('save_success')
        ->and($result['watermark'])->toBe(['file' => 'logo.png'])
        ->and($result['ferrors'])->toBe(['watermark' => ['xpos' => '[0..100]']]);
});
