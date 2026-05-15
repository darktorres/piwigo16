<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `get_batch_manager_prefilters` (dispatch).
 *
 * use this trigger to add prefilters into batch manager global
 *
 * Dispatched from: src/Piwigo/Admin/BatchManager/FilterResolver.php
 */
final readonly class GetBatchManagerPrefilters
{
    /**
     * @param array<mixed> $prefilters
     */
    public function __construct(
        public array $prefilters,
    ) {
    }

    /**
     * @param array<mixed> $prefilters
     */
    public function withPrefilters(array $prefilters): self
    {
        return new self($prefilters);
    }
}
