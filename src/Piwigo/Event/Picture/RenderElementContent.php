<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `render_element_content` filter. Its one real
 * dispatch site always starts `$content` from an empty string, expecting a
 * handler to populate it.
 */
final class RenderElementContent
{
    /**
     * @param array<string, mixed> $currentPicture
     */
    public function __construct(
        public string $content,
        public readonly array $currentPicture,
    ) {}
}
