<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `picture_modify_before_update` (dispatch).
 *
 * New in 2.6.2.
 *
 * Dispatched from: src/Piwigo/Controller/Admin/PhotoController.php
 */
final readonly class PictureModifyBeforeUpdate
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public array $data,
    ) {
    }
}
