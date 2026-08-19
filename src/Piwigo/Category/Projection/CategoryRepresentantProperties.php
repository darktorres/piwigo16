<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryService::getCategoryRepresentantProperties()}'s
 * own return shape. `src` is always a real thumbnail/derivative url string
 * -- {@see \Piwigo\Image\DerivativeImage::thumbUrl()}/`url()` both declare
 * `: string`, never an array; the method's own former `string|array<int|string,
 * mixed>` docblock union was stale.
 */
final readonly class CategoryRepresentantProperties
{
    public function __construct(
        public string $src,
        public string $url,
    ) {}

    /**
     * @return array{src: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'src' => $this->src,
            'url' => $this->url,
        ];
    }
}
