<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationMainPageContext;

test('toArray flattens main and group_options, and omits ORDER_BY_IS_CUSTOM when null', function (): void {
    $context = new ConfigurationMainPageContext(
        orderByIsCustom: null,
        main: ['CONF_GALLERY_TITLE' => 'My Gallery'],
        groupOptions: [1 => 'Family'],
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('ORDER_BY_IS_CUSTOM')
        ->and($result['main'])->toBe(['CONF_GALLERY_TITLE' => 'My Gallery'])
        ->and($result['group_options'])->toBe([1 => 'Family']);
});

test('toArray includes ORDER_BY_IS_CUSTOM when set', function (): void {
    $context = new ConfigurationMainPageContext(
        orderByIsCustom: true,
        main: [],
        groupOptions: [],
    );

    expect($context->toArray()['ORDER_BY_IS_CUSTOM'])->toBeTrue();
});
