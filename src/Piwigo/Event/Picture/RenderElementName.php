<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `render_element_name` (dispatch).
 *
 * Dispatched from: src/Piwigo/Html/HtmlService.php, src/Piwigo/Ws/Method/ImagesEndpoints.php
 */
final readonly class RenderElementName
{
    /**
     * @param array<mixed> $info
     */
    public function __construct(
        public string $elementName,
        public array $info,
    ) {
    }
}
