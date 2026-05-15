<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_derivative_url` (dispatch).
 *
 * New in 2.4
 *
 * Dispatched from: src/Piwigo/Image/DerivativeImage.php
 */
final readonly class GetDerivativeUrl
{
    public function __construct(
        public string $url,
        public \Piwigo\Image\DerivativeParams $value,
        public \Piwigo\Image\SrcImage $value2,
        public string $relUrl,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->value, $this->value2, $this->relUrl);
    }
}
