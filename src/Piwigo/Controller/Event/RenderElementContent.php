<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `render_element_content` filter. Its one real
 * dispatch site always starts `$content` from an empty string, expecting a
 * handler to populate it. Co-located here from `Piwigo\Event\Picture\RenderElementContent` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
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
