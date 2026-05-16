<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_element_url` (dispatch).
 *
 * Dispatched from: src/Piwigo/Url/UrlService.php
 */
final readonly class GetElementUrl
{
    /**
     * @param array<mixed> $elementInfo
     */
    public function __construct(
        public string $url,
        public array $elementInfo,
    ) {
    }
}
