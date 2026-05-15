<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_index_album_derivative_params` (dispatch).
 *
 * New in 2.4
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 */
final readonly class GetIndexAlbumDerivativeParams
{
    public function __construct(
        public \Piwigo\Image\DerivativeParams $value,
    ) {
    }

    public function withValue(\Piwigo\Image\DerivativeParams $value): self
    {
        return new self($value);
    }
}
