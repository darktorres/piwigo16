<?php

declare(strict_types=1);

namespace Piwigo\Category\Request;

/**
 * Validated `$_GET['slideshow']` shape for CategoryDefaultRenderer's
 * own slideshow-url building -- P26/SEC-40 Request DTO. The value only
 * ever gets echoed back verbatim into a URL parameter (never compared
 * or branched on), so a malformed non-string value collapses to the
 * same `''` the original's own `?? ''` fallback already used for
 * absence.
 */
final readonly class CategorySlideshowRequest
{
    private function __construct(
        public string $slideshow,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        $slideshow_raw = $get['slideshow'] ?? null;

        return new self(is_string($slideshow_raw) ? $slideshow_raw : '');
    }
}
