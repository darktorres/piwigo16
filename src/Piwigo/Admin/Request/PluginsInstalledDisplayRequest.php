<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET` display-toggle shape for
 * PluginsInstalledPageRenderer::render() (the "installed" tab of page
 * slug "plugins"). `show_details` is a
 * tri-state (absent/`'1'`/anything else) that persists into the session
 * when explicitly given; `incompatible_plugins`/`show_inactive` are
 * presence-only flags. No pattern validation needed for any of these.
 */
final readonly class PluginsInstalledDisplayRequest
{
    private function __construct(
        public ?bool $showDetails,
        public bool $isIncompatiblePluginsRequest,
        public bool $showInactive,
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
        $show_details_raw = $source['show_details'] ?? null;
        $showDetails = $show_details_raw === null ? null : (is_string($show_details_raw) && $show_details_raw === '1');

        return new self(
            $showDetails,
            isset($source['incompatible_plugins']),
            isset($source['show_inactive']),
        );
    }
}
