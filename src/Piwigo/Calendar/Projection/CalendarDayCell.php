<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarMonthly::buildMonthCalendar()}'s own
 * per-day grid cell -- 3 real variants: a padding cell before/after the
 * month has every field null; a day with no images has only `$day` set;
 * a day with images has every field set.
 */
final readonly class CalendarDayCell
{
    public function __construct(
        public ?int $day = null,
        public ?int $dow = null,
        public ?int $nbElements = null,
        public ?string $image = null,
        public ?string $uImgLink = null,
        public ?string $imageAlt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->day !== null) {
            $result['DAY'] = $this->day;
        }

        if ($this->dow !== null) {
            $result['DOW'] = $this->dow;
        }

        if ($this->nbElements !== null) {
            $result['NB_ELEMENTS'] = $this->nbElements;
        }

        if ($this->image !== null) {
            $result['IMAGE'] = $this->image;
        }

        if ($this->uImgLink !== null) {
            $result['U_IMG_LINK'] = $this->uImgLink;
        }

        if ($this->imageAlt !== null) {
            $result['IMAGE_ALT'] = $this->imageAlt;
        }

        return $result;
    }
}
