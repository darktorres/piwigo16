<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `render_element_description` (dispatch).
 *
 * Dispatched from: src/Piwigo/Html/HtmlService.php, src/Piwigo/Controller/PictureController.php, src/Piwigo/Ws/Method/CategoriesEndpoints.php
 */
final readonly class RenderElementDescription
{
    public function __construct(
        public string $elementDescription,
        public string $action,
    ) {
    }
}
