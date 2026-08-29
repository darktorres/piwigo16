<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * One theme in a resolved theme chain, parent-first (P58-A).
 *
 * `ThemeChain::walk()` built these as `array<string, mixed>` with an
 * optional `local_head` key, so every reader narrowed defensively --
 * `is_string($theme['id'] ?? null)`, `(bool) ($theme['load_css'] ?? true)`,
 * `is_array($theme) ? ($theme['local_head'] ?? null) : null` -- across
 * `ThemeBaseAssets` and four sites in `Template`, and the admin layout's
 * own `{foreach $themes as $theme}` read `$theme['id']` off `mixed`.
 *
 * `$localHead` is nullable rather than an absent key: it is set only when
 * the theme declares a local_head, that file is loaded for this chain, and
 * `realpath()` resolves it -- three conditions whose combined answer is
 * "there is a path or there is not".
 */
final readonly class ThemeChainEntry
{
    public function __construct(
        public string $id,
        public bool $loadCss,
        public ?string $localHead = null,
    ) {}
}
