<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SrcImage;

/**
 * Typed event for the legacy `get_derivative_url` filter. No handler is
 * registered for it anywhere today. Lives under `Piwigo\Image\Event\`,
 * not `Piwigo\Event\Picture\`, since it carries real
 * `Piwigo\Image\DerivativeParams`/`SrcImage` instances -- deptrac's
 * L0Data layer may depend on nothing. Mutable on `$url`; `$params`/
 * `$srcImage`/`$relUrl` stay context.
 */
final class GetDerivativeUrl
{
    public function __construct(
        public string $url,
        public readonly DerivativeParams $params,
        public readonly SrcImage $srcImage,
        public readonly string $relUrl,
    ) {}
}
