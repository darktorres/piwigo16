<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_element_url` (dispatch).
 *
 * Dispatched from: src/Piwigo/Url/UrlService.php
 */
final class GetElementUrl
{
    /**
     * @param array<string, mixed> $elementInfo
     */
    public function __construct(
        public string $url,
        public readonly array $elementInfo,
    ) {
    }
}
