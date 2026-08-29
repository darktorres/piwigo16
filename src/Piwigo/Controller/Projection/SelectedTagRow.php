<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * One tag chip in the gallery's "selected tags" strip, built by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} and rendered by
 * `include/selected_tags.inc.latte`.
 *
 * `$removeUrl` is the index URL for the current selection minus this tag,
 * so it only means anything when more than one tag is selected -- which is
 * exactly the condition the template puts around the link that uses it.
 */
final readonly class SelectedTagRow
{
    public function __construct(
        public string $tagName,
        public string $indexUrl,
        public string $removeUrl,
    ) {}
}
