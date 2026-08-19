<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PictureFormatsPageRenderer::render()}. `$formats`
 * is a real {@see \Piwigo\Image\Projection\ImageFormat} row plus 3
 * view-only fields -- see {@see \Piwigo\Admin\Projection\PictureFormatRow}.
 */
final readonly class PictureFormatsPageContext implements TemplatePageContext
{
    /**
     * @param list<PictureFormatRow> $formats
     */
    public function __construct(
        public string $addFormatsUrl,
        public string $imgSquareSrc,
        public array $formats,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADD_FORMATS_URL' => $this->addFormatsUrl,
            'IMG_SQUARE_SRC' => $this->imgSquareSrc,
            'FORMATS' => array_map(static fn (PictureFormatRow $format): array => $format->toArray(), $this->formats),
            'CSRF_TOKEN' => $this->pwgToken,
        ];
    }
}
