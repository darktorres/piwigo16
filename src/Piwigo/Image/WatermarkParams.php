<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Container for watermark configuration.
 */
final class WatermarkParams
{
    /** @var string */
    public $file = '';
    /** @var int[] */
    public $min_size = [500,500];
    /** @var int */
    public $xpos = 50;
    /** @var int */
    public $ypos = 50;
    /** @var int */
    public $xrepeat = 0;
    /** @var int */
    public $yrepeat = 0;
    /** @var int */
    public $opacity = 100;

    /**
     * @return array{file: string, min_size: array{int, int}, xpos: int, ypos: int, xrepeat: int, yrepeat: int, opacity: int}
     */
    public function toArray(): array
    {
        return [
            'file'     => $this->file,
            'min_size' => [$this->min_size[0], $this->min_size[1]],
            'xpos'     => $this->xpos,
            'ypos'     => $this->ypos,
            'xrepeat'  => $this->xrepeat,
            'yrepeat'  => $this->yrepeat,
            'opacity'  => $this->opacity,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $wm = new self();
        $wm->file     = is_string($row['file'] ?? null) ? $row['file'] : '';
        $minSizeRaw   = $row['min_size'] ?? null;
        if (is_array($minSizeRaw)) {
            $wm->min_size = [
                isset($minSizeRaw[0]) && is_numeric($minSizeRaw[0]) ? (int) $minSizeRaw[0] : 500,
                isset($minSizeRaw[1]) && is_numeric($minSizeRaw[1]) ? (int) $minSizeRaw[1] : 500,
            ];
        }
        $wm->xpos    = is_numeric($row['xpos'] ?? null) ? (int) $row['xpos'] : 50;
        $wm->ypos    = is_numeric($row['ypos'] ?? null) ? (int) $row['ypos'] : 50;
        $wm->xrepeat = is_numeric($row['xrepeat'] ?? null) ? (int) $row['xrepeat'] : 0;
        $wm->yrepeat = is_numeric($row['yrepeat'] ?? null) ? (int) $row['yrepeat'] : 0;
        $wm->opacity = is_numeric($row['opacity'] ?? null) ? (int) $row['opacity'] : 100;
        return $wm;
    }
}
