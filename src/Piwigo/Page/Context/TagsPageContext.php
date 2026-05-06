<?php

declare(strict_types=1);

namespace Piwigo\Page\Context;

/**
 * Page context for the tags cloud / alphabetic listing (TagsController).
 *
 * TagsController renders the tag directory page (all available tags).
 * Browsing photos by selected tag(s) goes through GalleryController
 * (section = 'tags') and uses AlbumPageContext.
 *
 * Consumed by #23 Wave 0 Latte partials:
 *   selected_tags.inc.php → _partials/selected-tags.latte
 */
final readonly class TagsPageContext
{
    /**
     * @param list<array<string,mixed>> $tags         All available tags (id, name, url_name, counter, …).
     * @param list<array<string,mixed>> $selectedTags Tags currently highlighted / pre-selected.
     * @param list<int>                 $photos       Image IDs when a subset is shown inline (may be empty).
     */
    public function __construct(
        public array  $tags,
        public array  $selectedTags,
        public array  $photos,
        public string $displayMode,
    ) {
    }
}
