<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdateMetadataResultPageContext;

test('toArray nests every counter under metadata_result', function (): void {
    $context = new SiteUpdateMetadataResultPageContext(
        elementsDone: 10,
        elementsCandidates: 12,
        errors: 2,
    );

    expect($context->toArray())
        ->toBe([
            'metadata_result' => [
                'NB_ELEMENTS_DONE' => 10,
                'NB_ELEMENTS_CANDIDATES' => 12,
                'NB_ERRORS' => 2,
            ],
        ]);
});
