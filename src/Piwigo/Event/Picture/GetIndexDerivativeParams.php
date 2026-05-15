<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_index_derivative_params` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryDefaultRenderer.php
 */
final readonly class GetIndexDerivativeParams
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
