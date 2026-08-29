<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

/**
 * `footer.latte`'s `$debug` block, built by
 * {@see \Piwigo\Page\PageTailRenderer::prepareTail()} from 2 independent
 * config flags: `$queriesList` under `CurrentConfig::$showQueries`;
 * `$time`/`$nbQueries`/`$sqlTime` together under `CurrentConfig::$showGt`.
 * All 4 fields are genuinely fixed (not a dynamic bag, despite this
 * class's own earlier docblock claiming otherwise), and both layouts
 * read them off this object directly -- the `toArray()` that used to
 * flatten it one line before the template is gone with its only
 * caller.
 */
final readonly class DebugInfo
{
    public function __construct(
        public ?string $queriesList = null,
        public ?string $time = null,
        public ?int $nbQueries = null,
        public ?string $sqlTime = null,
    ) {}
}
