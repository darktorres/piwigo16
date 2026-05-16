<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_comments_derivative_params` (dispatch).
 *
 * New in 2.4
 *
 * Dispatched from: src/Piwigo/Controller/CommentsController.php
 */
final readonly class GetCommentsDerivativeParams
{
    public function __construct(
        public \Piwigo\Image\DerivativeParams $value,
    ) {
    }
}
