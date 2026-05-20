<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Preset watermark positions — drives the 5 named slots in the admin
 * "Watermark" form. Each case carries the `(xpos, ypos)` percentages
 * that get written to the watermark config (custom positions skip
 * the preset path and store xpos/ypos directly).
 */
enum WatermarkPosition: string
{
    case TopLeft     = 'topleft';
    case TopRight    = 'topright';
    case Middle      = 'middle';
    case BottomLeft  = 'bottomleft';
    case BottomRight = 'bottomright';

    /** @return array{int, int} */
    public function coords(): array
    {
        return match ($this) {
            self::TopLeft     => [0,   0],
            self::TopRight    => [100, 0],
            self::Middle      => [50,  50],
            self::BottomLeft  => [0,   100],
            self::BottomRight => [100, 100],
        };
    }

    public static function fromCoords(int $xpos, int $ypos): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->coords() === [$xpos, $ypos]) {
                return $case;
            }
        }
        return null;
    }
}
