<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET['section']` for HelpPageRenderer::render() (page slug
 * "help") -- P26/SEC-40 Request DTO. No pattern validation needed:
 * `Tabsheet::select()` (called immediately with this value) already
 * allowlists unconditionally -- any name not matching a real, pre-
 * registered help sheet silently falls back to the first registered
 * sheet instead of using the raw value, so `$tabsheet->selected` (later
 * spliced into a `help/help_{selected}.html` Lang::load() path) can never
 * actually be arbitrary user input regardless of what this DTO returns.
 * This DTO exists to remove the raw superglobal read and name the
 * concept, not to add rejection behavior that would duplicate
 * Tabsheet's own real gate.
 */
final readonly class HelpSectionRequest
{
    private function __construct(
        public string $section,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $section = $source['section'] ?? null;

        return new self(is_string($section) ? $section : 'add_photos');
    }
}
