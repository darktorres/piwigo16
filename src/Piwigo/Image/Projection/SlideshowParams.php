<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageService::getDefaultSlideshowParams()}'s own
 * fixed result shape.
 */
final readonly class SlideshowParams
{
    public function __construct(
        public int $period,
        public bool $repeat,
        public bool $play,
    ) {}

    /**
     * {@see \Piwigo\Image\ImageService::decodeSlideshowParams()}/{@see
     * \Piwigo\Image\ImageService::encodeSlideshowParams()} splice
     * dynamically-keyed regex matches onto this -- unbox to array at that
     * mutation boundary.
     *
     * @return array{period: int, repeat: bool, play: bool}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'repeat' => $this->repeat,
            'play' => $this->play,
        ];
    }
}
