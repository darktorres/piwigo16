<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * One of the picture page's five navigation slots -- first, previous,
 * current, next, last -- as the templates read it.
 *
 * Composes {@see PictureElement} rather than copying its fields: the five
 * entries are the same photos `PictureController::__invoke()` already
 * built, with the slideshow parameters of the moment appended to each
 * URL. `$imgUrl` is that appended form, which is why it lives here and
 * not on the element -- the element is the photo, this is the link to it
 * from where the visitor currently is.
 *
 * `$downloadUrl` and `$formats` are current-only, and further conditional
 * on the download icon being enabled and the visitor being allowed the
 * original -- null and empty respectively otherwise.
 *
 * @see \Piwigo\Controller\Projection\PictureElement for the photo itself
 */
final readonly class PictureNavEntry
{
    /**
     * @param list<array<string, mixed>> $formats
     */
    public function __construct(
        public PictureElement $element,
        public string $imgUrl,
        public ?string $downloadUrl = null,
        public array $formats = [],
    ) {}
}
