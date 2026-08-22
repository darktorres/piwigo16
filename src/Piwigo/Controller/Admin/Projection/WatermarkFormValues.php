<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own `'watermark'` case's `$watermark` shape -- the current watermark
 * settings pre-filled into the `'watermark'` tab's form, derived from
 * {@see \Piwigo\Image\WatermarkParams}. `$position` is computed (not a
 * real `WatermarkParams` field): one of the 5 named presets if
 * `$wm->xpos`/`$wm->ypos` match exactly and there's no repeat, else
 * `'custom'`. `configuration_watermark.latte` still reads these via
 * `$watermark['key']` (through {@see ConfigurationWatermarkView}'s own
 * array-typed `$watermark`), so `toArray()` reproduces that exact shape.
 */
final readonly class WatermarkFormValues
{
    public function __construct(
        public string $file,
        public int $minw,
        public int $minh,
        public int $xpos,
        public int $ypos,
        public int $xrepeat,
        public int $yrepeat,
        public int $opacity,
        public string $position,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'minw' => $this->minw,
            'minh' => $this->minh,
            'xpos' => $this->xpos,
            'ypos' => $this->ypos,
            'xrepeat' => $this->xrepeat,
            'yrepeat' => $this->yrepeat,
            'opacity' => $this->opacity,
            'position' => $this->position,
        ];
    }
}
