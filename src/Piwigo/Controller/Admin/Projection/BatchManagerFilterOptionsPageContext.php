<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::handle()}'s
 * own dimensions/filesize filter setup.
 */
final readonly class BatchManagerFilterOptionsPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $dimensions
     * @param array<string, mixed> $filesize
     */
    public function __construct(
        public array $dimensions,
        public array $filesize,
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
