<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `pwg_log_allowed` (dispatch).
 *
 * Dispatched from: src/Piwigo/Core/Util.php
 */
final readonly class PwgLogAllowed
{
    public function __construct(
        public bool $doLog,
        public int $imageId,
        public string $imageType,
    ) {
    }

    public function withDoLog(bool $doLog): self
    {
        return new self($doLog, $this->imageId, $this->imageType);
    }
}
