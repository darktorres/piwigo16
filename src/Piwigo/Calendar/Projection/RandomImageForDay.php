<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarRepository::findRandomImageForDay()}'s own
 * row shape -- {@see \Piwigo\Calendar\CalendarMonthly::buildMonthCalendar()}'s
 * real (and only) consumer, its per-day thumbnail-preview pick.
 *
 * `toArray()` preserves the exact original snake_case shape (minus `dow`,
 * not part of this contract): {@see \Piwigo\Image\SrcImage}'s own
 * constructor accepts `array<string, mixed>` (not this DTO) -- a
 * documented cross-domain generic-row-reader, only ever reading
 * `id`/`path`/`file`/`representative_ext`/`width`/`height`/`rotation` (in
 * scope for a future elimination pass, not this one) -- so the caller
 * calls `toArray()` before handing a row to it, same boundary-unwrap
 * convention every other Projection in this codebase uses.
 */
final readonly class RandomImageForDay
{
    public function __construct(
        public int $id,
        public string $file,
        public ?string $representativeExt,
        public string $path,
        public ?int $width,
        public ?int $height,
        public ?int $rotation,
        public int $dow,
    ) {}

    /**
     * @return array{id: int, file: string, representative_ext: ?string, path: string, width: ?int, height: ?int, rotation: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'file' => $this->file,
            'representative_ext' => $this->representativeExt,
            'path' => $this->path,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
        ];
    }
}
