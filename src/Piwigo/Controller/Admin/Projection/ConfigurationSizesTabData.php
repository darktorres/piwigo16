<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The "sizes" tab's four original-resize form fields, as
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController} feeds them to
 * `configuration_sizes.latte`.
 *
 * Two paths build this, and their value types genuinely differ, which is
 * what the unions record rather than hide:
 *
 *  - a fresh render takes all four straight from real, non-null
 *    `CurrentConfig` properties, so they are int/int/int/bool;
 *  - a validation-failure redisplay echoes back what the user actually
 *    typed -- `strip_tags($_POST[$field])`, deliberately including values
 *    that failed validation, because the point is to show them again -- so
 *    they are strings, and any field absent from the POST is null.
 *
 * The template only ever echoes these into a `value=` attribute or tests
 * the checkbox for truthiness, so the two paths render identically for
 * equal values: `value="200"` whether 200 arrived as an int or a string.
 *
 * Before P58-A the failure path bypassed this class entirely and handed the
 * View a raw array, which is why the View's own property was
 * `array<string, mixed>|null`. Both paths now build this.
 */
final readonly class ConfigurationSizesTabData
{
    public function __construct(
        public int|string|null $originalResizeMaxwidth,
        public int|string|null $originalResizeMaxheight,
        public int|string|null $originalResizeQuality,
        // A checkbox: unlike the three text fields beside it, there is no
        // "submitted but invalid" value to echo back, only checked or not.
        public bool $originalResize,
    ) {}
}
