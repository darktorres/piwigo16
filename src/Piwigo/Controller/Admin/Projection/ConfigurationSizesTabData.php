<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'sizes'` tab's own `$sizes` slice, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'sizes'` case on a fresh render (not a validation-failure redisplay,
 * which reuses {@see ConfigurationSizesResult}'s own `$sizes` array
 * instead -- a separate, already-array-shaped path out of this
 * conversion's scope). All 4 fields come straight from real, non-null
 * `CurrentConfig` properties on this fresh-render path, unlike the
 * reference's own shared-nullable-with-$isGd shape. `configuration_sizes.
 * latte` still reads these via `$sizes['key']` (through {@see
 * ConfigurationSizesView}'s own array-typed `$sizes`), so `toArray()`
 * reproduces that exact shape.
 */
final readonly class ConfigurationSizesTabData
{
    public function __construct(
        public int $originalResizeMaxwidth,
        public int $originalResizeMaxheight,
        public int $originalResizeQuality,
        public bool $originalResize,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_resize_maxwidth' => $this->originalResizeMaxwidth,
            'original_resize_maxheight' => $this->originalResizeMaxheight,
            'original_resize_quality' => $this->originalResizeQuality,
            'original_resize' => $this->originalResize,
        ];
    }
}
