<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()} after
 * a "directories / files" synchronization run.
 */
final readonly class SiteUpdateSyncResultPageContext implements TemplatePageContext
{
    public function __construct(
        public int $newCategories,
        public int $delCategories,
        public int $newElements,
        public int $delElements,
        public int $updElements,
        public int $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'update_result' => [
                'NB_NEW_CATEGORIES' => $this->newCategories,
                'NB_DEL_CATEGORIES' => $this->delCategories,
                'NB_NEW_ELEMENTS' => $this->newElements,
                'NB_DEL_ELEMENTS' => $this->delElements,
                'NB_UPD_ELEMENTS' => $this->updElements,
                'NB_ERRORS' => $this->errors,
            ],
        ];
    }
}
