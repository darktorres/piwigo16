<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed event for the legacy `picture_modify_before_update` filter. No
 * handler is registered for it anywhere today. No context -- every real
 * call site passes only the update data. Co-located here from `Piwigo\Event\Picture\PictureModifyBeforeUpdate` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class PictureModifyBeforeUpdate
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data,
    ) {}
}
