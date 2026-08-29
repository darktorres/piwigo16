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
     * Every value is a string, matching the other shape
     * `ConfigurationWatermarkView::$watermark` receives: on a validation
     * failure the tab re-renders the raw `$_POST['w']` map, so the user
     * sees back exactly what they typed. The template only ever puts these
     * in `value=""` attributes and compares `position` against literals,
     * so the two paths agree once the ints are stringified here rather
     * than the property widening to `mixed` to hold both.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'minw' => (string) $this->minw,
            'minh' => (string) $this->minh,
            'xpos' => (string) $this->xpos,
            'ypos' => (string) $this->ypos,
            'xrepeat' => (string) $this->xrepeat,
            'yrepeat' => (string) $this->yrepeat,
            'opacity' => (string) $this->opacity,
            'position' => $this->position,
        ];
    }
}
