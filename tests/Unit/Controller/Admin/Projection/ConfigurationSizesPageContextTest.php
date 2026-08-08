<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationSizesPageContext;

test('toArray omits every key when all fields are null', function (): void {
    $context = new ConfigurationSizesPageContext(
        saveSuccess: null,
        derivatives: null,
        ferrors: null,
        resizeQuality: null,
    );

    expect($context->toArray())->toBe([]);
});

test('toArray includes save_success alone on the success branch', function (): void {
    $context = new ConfigurationSizesPageContext(
        saveSuccess: 'Your configuration settings are saved',
        derivatives: null,
        ferrors: null,
        resizeQuality: null,
    );

    expect($context->toArray())->toBe([
        'save_success' => 'Your configuration settings are saved',
    ]);
});

test('toArray includes derivatives, ferrors and resize_quality together on the error branch', function (): void {
    $context = new ConfigurationSizesPageContext(
        saveSuccess: null,
        derivatives: ['square' => ['w' => '100']],
        ferrors: ['resize_quality' => '[50..98]'],
        resizeQuality: '150',
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('save_success')
        ->and($result['derivatives'])->toBe(['square' => ['w' => '100']])
        ->and($result['ferrors'])->toBe(['resize_quality' => '[50..98]'])
        ->and($result['resize_quality'])->toBe('150');
});
