<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

use Piwigo\Common\ValueObject\ImageId;

/**
 * Typed event for the legacy `allow_increment_element_hit_count`
 * filter. No handler is registered for it anywhere today. Carries
 * `$imageId` in addition to the reference's own `$contentNotSet` -- this
 * branch's real dispatch site (`PictureController.php`) passes it as
 * extra context, which the reference's own class doesn't. Mutable on
 * `$incHitCount`; `$imageId` stays context.
 */
final class AllowIncrementElementHitCount
{
    public function __construct(
        public bool $incHitCount,
        public readonly ImageId $imageId,
    ) {}
}
