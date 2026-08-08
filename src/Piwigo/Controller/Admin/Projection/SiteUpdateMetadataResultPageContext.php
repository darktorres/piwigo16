<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()} after
 * a metadata synchronization run.
 */
final readonly class SiteUpdateMetadataResultPageContext implements TemplatePageContext
{
    public function __construct(
        public int $elementsDone,
        public int $elementsCandidates,
        public int $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'metadata_result' => [
                'NB_ELEMENTS_DONE' => $this->elementsDone,
                'NB_ELEMENTS_CANDIDATES' => $this->elementsCandidates,
                'NB_ERRORS' => $this->errors,
            ],
        ];
    }
}
