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
 * All seven form fields are represented. `minw`/`minh`/`xrepeat`/`yrepeat`
 * were absent for as long as nothing validated them: the template carried
 * error markup for all four, and since no code ever wrote those keys the
 * branches could not render. P58 removed the dead markup and recorded the
 * gap; the validation exists now, so the markup is back and these four
 * carry it.
 */
final readonly class WatermarkFormErrors
{
    public function __construct(
        public ?string $watermarkImage = null,
        public ?string $xpos = null,
        public ?string $ypos = null,
        public ?string $opacity = null,
        public ?string $minw = null,
        public ?string $minh = null,
        public ?string $xrepeat = null,
        public ?string $yrepeat = null,
    ) {}
}
