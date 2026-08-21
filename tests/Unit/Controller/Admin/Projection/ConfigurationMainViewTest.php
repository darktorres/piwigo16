<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationMainView;

/**
 * @param array<string, mixed> $main
 */
function makeConfigurationMainView(array $main): ConfigurationMainView
{
    return new ConfigurationMainView(
        main: $main,
        groupOptions: [],
        fAction: '',
        saveSuccess: null,
        isWebmaster: 0,
        csrfToken: '',
    );
}

test('exposedPageData counts order_by_options entries', function (): void {
    $view = makeConfigurationMainView([
        'order_by_options' => [
            '' => '',
            'file ASC' => 'File name',
        ],
    ]);

    expect($view->exposedPageData())
        ->toBe([
            'order_by_options_count' => 2,
        ]);
});

test('exposedPageData falls back to 0 when order_by_options is not an array', function (): void {
    $view = makeConfigurationMainView([]);

    expect($view->exposedPageData())
        ->toBe([
            'order_by_options_count' => 0,
        ]);
});
