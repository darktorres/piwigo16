<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `allow_increment_element_hit_count`
 * filter. No handler is registered for it anywhere today. Carries
 * `$imageId` in addition to the reference's own `$contentNotSet` -- this
 * branch's real dispatch site (`PictureController.php`) passes it as
 * extra context, which the reference's own class doesn't.
 */
final readonly class AllowIncrementElementHitCount
{
    public function __construct(
        public bool $incHitCount,
        public int $imageId,
    ) {}
}
