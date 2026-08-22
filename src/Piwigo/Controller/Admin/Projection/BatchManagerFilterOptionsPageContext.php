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
            'dimensions' => $this->dimensions->toArray(),
            'filesize' => $this->filesize->toArray(),
        ];
    }
}
