<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::handle()}'s
 * own dimensions/filesize filter setup.
 *
 * The context stays, rather than being replaced by a constructed
 * `BatchManagerFilterView` (P58-A's own §4): that View is contract-only
 * because `batch_manager_filter.inc.latte` is `{include}`d by two pages
 * rather than rendered, so its variables have to reach the shared template
 * scope -- which is exactly what `assignContext()` does. What comes out
 * here is the flatten: `toArray()` passes both value objects through
 * untouched, and the template reads their properties.
 */
final readonly class BatchManagerFilterOptionsPageContext implements TemplatePageContext
{
    public function __construct(
        public DimensionFilterOptions $dimensions,
        public FilesizeFilterOptions $filesize,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'dimensions' => $this->dimensions,
            'filesize' => $this->filesize,
        ];
    }
}
