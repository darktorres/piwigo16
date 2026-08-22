<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * computeFilesizeOptions()}'s return shape -- the batch manager's
 * filesize-filter slider options, fed to `batch_manager_filter.inc.latte`.
 * `list` is a comma-joined list of every distinct filesize (MB, slider
 * tick marks).
 */
final readonly class FilesizeFilterOptions
{
    public function __construct(
        public string $list,
        public FilesizeBounds $bounds,
        public FilesizeBounds $selected,
    ) {}

    /**
     * @return array{list: string, bounds: array{min: float|int|string, max: float|int|string}, selected: array{min: float|int|string, max: float|int|string}}
     */
    public function toArray(): array
    {
        return [
            'list' => $this->list,
            'bounds' => $this->bounds->toArray(),
            'selected' => $this->selected->toArray(),
        ];
    }
}
