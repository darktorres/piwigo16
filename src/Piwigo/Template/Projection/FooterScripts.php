<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Piwigo\Template\Combinable;

/**
 * {@see \Piwigo\Template\ScriptLoader::get_footer_scripts()}'s own fixed
 * 2-list result (footer-sync scripts, footer-async scripts), previously a
 * bare `[Combinable[], Combinable[]]` tuple.
 */
final readonly class FooterScripts
{
    /**
     * @param Combinable[] $sync
     * @param Combinable[] $async
     */
    public function __construct(
        public array $sync,
        public array $async,
    ) {}
}
