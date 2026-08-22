<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * computeFilesizeOptions()}'s own `bounds`/`selected` sub-shape. `min`/
 * `max` are a genuine union, not unnarrowed laziness: the real-data path
 * yields `sprintf('%.1f', ...)`-formatted decimal strings, the
 * no-photos-yet fallback yields plain ints, and `selected` may further
 * override either with a real `float` from the session filter.
 */
final readonly class FilesizeBounds
{
    public function __construct(
        public float|int|string $min,
        public float|int|string $max,
    ) {}

    /**
     * @return array{min: float|int|string, max: float|int|string}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}
