<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `pwg_log_update_last_visit` (dispatch).
 *
 * Dispatched from: src/Piwigo/Core/Util.php
 */
final readonly class PwgLogUpdateLastVisit
{
    public function __construct(
        public bool $update,
    ) {
    }
}
