<?php

declare(strict_types=1);

namespace Piwigo\Template\Event;

use Piwigo\Template\Combinable;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\Template;

/**
 * Typed event for the legacy `combinable_preparse` notification. No
 * handler is registered for it anywhere today. Lives under
 * `Piwigo\Template\Event\`, not `Piwigo\Event\Template\`, since it
 * carries real `Piwigo\Template\Template`/`Combinable`/`FileCombiner`
 * instances -- deptrac's L0Data layer may depend on nothing.
 */
final readonly class CombinablePreparse
{
    public function __construct(
        public Template $template,
        public Combinable $combinable,
        public FileCombiner $fileCombiner,
    ) {}
}
