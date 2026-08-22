<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

/**
 * One EXIF or IPTC panel in the picture page's metadata block, built by
 * {@see \Piwigo\Picture\PictureMetadataRenderer::render()}. `$lines` stays
 * a plain map -- its keys are field labels drawn from
 * {@see \Piwigo\Config\CurrentConfig::$showExifFields}/`$showIptcMapping`,
 * genuinely admin-configurable/arbitrary, same reasoning as
 * `CurrentConfig`'s other residual dictionaries.
 */
final readonly class MetadataPanel
{
    /**
     * @param array<string, mixed> $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
    ) {}

    /**
     * @return array{TITLE: string, lines: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'TITLE' => $this->title,
            'lines' => $this->lines,
        ];
    }
}
