<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `derivative_params_get` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/ImageDerivativeController.php
 */
final readonly class DerivativeParamsGet
{
    public function __construct(
        public \Piwigo\Image\DerivativeParams $params,
    ) {
    }
}
