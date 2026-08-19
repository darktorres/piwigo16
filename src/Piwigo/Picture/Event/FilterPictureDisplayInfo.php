<?php

declare(strict_types=1);

namespace Piwigo\Picture\Event;

/**
 * Typed filter event letting a plugin conditionally hide a field from
 * the picture page's own "display info" panel -- real dispatch site:
 * Controller\PictureController, right after reading
 * CurrentConfig::$pictureInformations, before it's threaded into
 * Controller\Projection\PictureView.
 *
 * Real caller this was ported for: AdminTools_16.3.0's own
 * set_prefilter('picture', 'admintools_remove_privacy') -- hides the
 * privacy_level field when its own quick-edit panel already shows an
 * equivalent control. A handler unsets the key it wants hidden.
 * `$imageId` mirrors `RenderElementContent::$currentPicture['id']`'s own
 * precedent in this exact controller -- lets a handler scope itself to a
 * specific picture instead of every render site-wide.
 *
 * A genuinely new capability, not a legacy-hook 1:1 translation --
 * `admintools_remove_privacy()` itself worked via a Smarty compile-time
 * prefilter, which has no equivalent at all in this Latte-compiling
 * fork (see docs/events-legacy-map.md's own note on this class of gap).
 */
final class FilterPictureDisplayInfo
{
    /**
     * @param array<string, bool> $displayInfo
     */
    public function __construct(
        public array $displayInfo,
        public readonly int $imageId,
    ) {}
}
