<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `render_element_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/PictureController.php
 */
final readonly class RenderElementContent
{
    /**
     * @param array<mixed> $currentPicture
     */
    public function __construct(
        public string $content,
        public array $currentPicture,
    ) {
    }
}
