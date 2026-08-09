<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for the legacy `load_image_library` filter (notify). No
 * handler is registered for it anywhere today. Typed `object`, not
 * `Piwigo\Admin\Image\PwgImage`, even though its one real dispatch site
 * (`PwgImage::__construct()`) always passes `$this` -- matches the
 * reference's own deliberate choice (same reasoning as
 * BlockManagerPrepareDisplay/BlockManagerApply), keeping this class free
 * of a first-party dependency (deptrac's L0Data layer may depend on
 * nothing).
 */
final readonly class LoadImageLibrary
{
    public function __construct(
        public object $value,
    ) {}
}
