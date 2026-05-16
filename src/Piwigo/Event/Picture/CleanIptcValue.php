<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `clean_iptc_value` (dispatch).
 *
 * Dispatched from: src/Piwigo/Metadata/MetadataService.php
 */
final readonly class CleanIptcValue
{
    public function __construct(
        public string $value,
    ) {
    }
}
