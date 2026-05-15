<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_element_metadata_available` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/PictureController.php
 *
 * Not present in tools/triggers_list.php — caught during B5 multi-line
 * dispatch audit. The original dispatch site uses multi-line formatting
 * that B3's single-line regex missed.
 */
final readonly class GetElementMetadataAvailable
{
    /**
     * @param array<mixed> $currentPicture
     */
    public function __construct(
        public bool $available,
        public array $currentPicture,
    ) {
    }

    public function withAvailable(bool $available): self
    {
        return new self($available, $this->currentPicture);
    }
}
