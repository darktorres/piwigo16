<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationMainPageContext;

test('toArray flattens main and group_options under their template keys', function (): void {
    $context = new ConfigurationMainPageContext(
        main: [
            'CONF_GALLERY_TITLE' => 'My Gallery',
        ],
        groupOptions: [
            1 => 'Family',
        ],
    );

    $result = $context->toArray();

    expect($result['main'])->toBe([
        'CONF_GALLERY_TITLE' => 'My Gallery',
    ])
        ->and($result['group_options'])->toBe([
            1 => 'Family',
        ])
        // The two keys above are the whole contract -- ORDER_BY_IS_CUSTOM
        // was the only conditional one and went with order_by_custom.
        ->and(array_keys($result))
        ->toBe(['main', 'group_options']);
});

test('toArray keeps both keys present when either input is empty', function (): void {
    $result = new ConfigurationMainPageContext(main: [], groupOptions: [])->toArray();

    expect($result)
        ->toBe([
            'main' => [],
            'group_options' => [],
        ]);
});
