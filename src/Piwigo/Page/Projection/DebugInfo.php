<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Latte\Runtime\Html;

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
 *
 * $queriesList is `RequestMetrics::$debugOutput` -- every real writer
 * ({@see \Piwigo\Core\TimingHelper::debug()}) only ever interpolates a
 * hardcoded literal caller label plus timing/count numbers into its own
 * `<p>...</p>` wrapper, never external/user data, so it's genuinely
 * trusted, pre-formed HTML (P59), typed `Html` here rather than a plain
 * string.
 */
final readonly class DebugInfo
{
    public function __construct(
        public ?Html $queriesList = null,
        public ?string $time = null,
        public ?int $nbQueries = null,
        public ?string $sqlTime = null,
    ) {}
}
