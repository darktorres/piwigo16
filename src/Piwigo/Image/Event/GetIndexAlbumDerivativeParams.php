<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

use Piwigo\Image\DerivativeParams;

/**
 * Typed event for the legacy `get_index_album_derivative_params`
 * filter. No handler is registered for it anywhere today. Lives under
 * `Piwigo\Image\Event\`, not `Piwigo\Event\Picture\`, since it carries a
 * real `Piwigo\Image\DerivativeParams` instance -- deptrac's L0Data
 * layer may depend on nothing. No context -- every real call site passes
 * only the params.
 */
final class GetIndexAlbumDerivativeParams
{
    public function __construct(
        public DerivativeParams $params,
    ) {}
}
