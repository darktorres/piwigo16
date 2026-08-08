<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdateIntroductionPageContext;

test('toArray nests every property under introduction', function (): void {
    $context = new SiteUpdateIntroductionPageContext(
        sync: 'files',
        syncMeta: true,
        displayInfo: false,
        addToCaddie: true,
        subcatsIncluded: false,
        privacyLevelSelected: 2,
        metaAll: true,
        metaEmptyOverrides: false,
        privacyLevelOptions: [0 => 'Everybody', 2 => 'Level 2'],
    );

    expect($context->toArray())->toBe([
        'introduction' => [
            'sync' => 'files',
            'sync_meta' => true,
            'display_info' => false,
            'add_to_caddie' => true,
            'subcats_included' => false,
            'privacy_level_selected' => 2,
            'meta_all' => true,
            'meta_empty_overrides' => false,
            'privacy_level_options' => [0 => 'Everybody', 2 => 'Level 2'],
        ],
    ]);
});
