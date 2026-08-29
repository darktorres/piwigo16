<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * `ThemeChain::resolve()`'s own return value: the fully walked
 * parent/child theme chain, ready for `Template::setTheme()` to apply
 * directly (child-first directories for file-lookup precedence,
 * parent-first `$themes` entries, and one already-merged `$themeconf`
 * with child keys winning over parent keys) -- see `ThemeChain::resolve()`'s
 * own docblock for why a single pre-computed value object replaces the
 * former recursive `Template::append()` side effects.
 */
final readonly class ThemeChainResolution
{
    /**
     * @param list<string> $dirs
     * @param list<ThemeChainEntry> $themes parent-first
     * @param array<string, mixed> $themeconf
     */
    public function __construct(
        public array $dirs,
        public array $themes,
        public array $themeconf,
    ) {}
}
