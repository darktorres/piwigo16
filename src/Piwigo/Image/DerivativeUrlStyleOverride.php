<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

/**
 * Explicit per-call override for the 3 CurrentConfig properties that
 * control derivative-image URL rendering style (questionMarkInUrls/
 * phpExtensionInUrls/derivativeUrlStyle), passed to DerivativeImage's
 * constructor/url() factory instead of mutating the shared CurrentConfig
 * instance -- a mutation would otherwise leak into every other consumer
 * for the rest of the request (and across requests under worker mode).
 * Each field null means "use CurrentConfig's own current value," matching
 * today's un-overridden behavior exactly.
 */
final readonly class DerivativeUrlStyleOverride
{
    public function __construct(
        public ?bool $questionMarkInUrls = null,
        public ?bool $phpExtensionInUrls = null,
        public ?int $derivativeUrlStyle = null,
    ) {}
}
