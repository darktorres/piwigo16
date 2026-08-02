<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `picture_modify_before_update` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class PictureModifyBeforeUpdate
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data,
    ) {}
}
