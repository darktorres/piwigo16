<?php

declare(strict_types=1);

namespace Piwigo\Picture;

/** Immutable slideshow settings: playback period, repeat flag, and play state. */
final readonly class SlideshowParams
{
    public function __construct(
        public int|float $period,
        public bool $repeat,
        public bool $play,
    ) {
    }
}
