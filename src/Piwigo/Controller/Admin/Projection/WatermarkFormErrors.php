<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * `configuration_watermark.latte`'s validation-failure messages, produced by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController}'s watermark
 * POST handler.
 *
 * Was a two-level bag -- `$errors['watermarkImage']` a string beside
 * `$errors['watermark']` an `array<string, string>` -- read through eleven
 * `isset()` checks. Flat here, because the nesting carried no meaning the
 * template used: every read was of a leaf.
 *
 * Only these four exist. The template also tested `watermark.minw`,
 * `.minh`, `.xrepeat` and `.yrepeat`, and nothing has ever written them --
 * those four form fields go through no validation at all, so their error
 * markup could not render. The dead branches are gone; the missing
 * validation is a real gap, but adding it would be new behaviour and is
 * left alone.
 */
final readonly class WatermarkFormErrors
{
    public function __construct(
        public ?string $watermarkImage = null,
        public ?string $xpos = null,
        public ?string $ypos = null,
        public ?string $opacity = null,
    ) {}
}
