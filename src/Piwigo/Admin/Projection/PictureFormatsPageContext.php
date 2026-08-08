<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PictureFormatsPageRenderer::render()}. `$formats`
 * stays a loose row shape: each entry is a real format DB row plus 3
 * view-only keys spliced on per row (`download_url`/`label`/`filesize`),
 * not a fixed structural shape worth minting its own DTO for here.
 */
final readonly class PictureFormatsPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $formats
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
            'FORMATS' => $this->formats,
            'PWG_TOKEN' => $this->pwgToken,
        ];
    }
}
