<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

use Piwigo\Controller\Projection\PictureElement;

/**
 * Typed event for the legacy `render_element_content` filter. Its one real
 * dispatch site always starts `$content` from an empty string, expecting a
 * handler to populate it.
 */
final class RenderElementContent
{
    public function __construct(
        public string $content,
        public readonly PictureElement $currentPicture,
    ) {}
}
