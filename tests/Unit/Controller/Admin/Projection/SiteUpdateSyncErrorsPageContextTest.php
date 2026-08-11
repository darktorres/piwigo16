<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdateSyncErrorsPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new SiteUpdateSyncErrorsPageContext(
        syncErrors: [[
            'ELEMENT' => 'a.jpg',
            'LABEL' => 'PWG-ERROR-NO-FS (file missing)',
        ]],
        syncErrorCaptions: [[
            'TYPE' => 'PWG-ERROR-NO-FS',
            'LABEL' => 'file missing',
        ]],
        syncInfos: [[
            'ELEMENT' => 'b.jpg',
            'LABEL' => 'metadata updated',
        ]],
    );

    expect($context->toArray())
        ->toBe([
            'sync_errors' => [[
                'ELEMENT' => 'a.jpg',
                'LABEL' => 'PWG-ERROR-NO-FS (file missing)',
            ]],
            'sync_error_captions' => [[
                'TYPE' => 'PWG-ERROR-NO-FS',
                'LABEL' => 'file missing',
            ]],
            'sync_infos' => [[
                'ELEMENT' => 'b.jpg',
                'LABEL' => 'metadata updated',
            ]],
        ]);
});

test('toArray includes empty lists (not omitted) when nothing to report', function (): void {
    $context = new SiteUpdateSyncErrorsPageContext(
        syncErrors: [],
        syncErrorCaptions: [],
        syncInfos: [],
    );

    expect($context->toArray())
        ->toBe([
            'sync_errors' => [],
            'sync_error_captions' => [],
            'sync_infos' => [],
        ]);
});
