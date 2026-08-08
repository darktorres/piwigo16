<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * Shared per-extension row shape for
 * {@see \Piwigo\Image\ImageRepository::findExtensionBreakdown()} (grouped
 * in PHP from every `images` row's path) and
 * {@see \Piwigo\Image\ImageRepository::findFormatExtensionBreakdown()}
 * (grouped in DQL against `image_format`) -- both feed
 * `Controller\Admin\IntroSubController`'s own storage chart, and the
 * former additionally feeds `Admin\PiwigoInfosSender`'s telemetry
 * payload.
 */
final readonly class ExtensionBreakdown
{
    public function __construct(
        public string $ext,
        public int $counter,
        public int $filesize,
    ) {}

    /**
     * @return array{ext: string, counter: int, filesize: int}
     */
    public function toArray(): array
    {
        return [
            'ext' => $this->ext,
            'counter' => $this->counter,
            'filesize' => $this->filesize,
        ];
    }
}
