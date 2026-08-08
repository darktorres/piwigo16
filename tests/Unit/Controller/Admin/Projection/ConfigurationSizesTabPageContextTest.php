<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationSizesTabPageContext;

test('toArray omits every key when all fields are null', function (): void {
    $context = new ConfigurationSizesTabPageContext(
        isGd: null,
        sizes: null,
        derivatives: null,
        resizeQuality: null,
        customDerivatives: null,
    );

    expect($context->toArray())->toBe([]);
});

test('toArray includes is_gd and sizes together', function (): void {
    $context = new ConfigurationSizesTabPageContext(
        isGd: true,
        sizes: ['original_resize_maxwidth' => 3000],
        derivatives: null,
        resizeQuality: null,
        customDerivatives: null,
    );

    $result = $context->toArray();

    expect($result['is_gd'])->toBeTrue()
        ->and($result['sizes'])->toBe(['original_resize_maxwidth' => 3000])
        ->and($result)->not->toHaveKey('derivatives');
});

test('toArray includes derivatives, resize_quality and custom_derivatives together', function (): void {
    $context = new ConfigurationSizesTabPageContext(
        isGd: null,
        sizes: null,
        derivatives: ['square' => ['enabled' => true]],
        resizeQuality: 95,
        customDerivatives: ['t' => 'today'],
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('is_gd')
        ->and($result['derivatives'])->toBe(['square' => ['enabled' => true]])
        ->and($result['resize_quality'])->toBe(95)
        ->and($result['custom_derivatives'])->toBe(['t' => 'today']);
});
