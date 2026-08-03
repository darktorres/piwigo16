<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

/**
 * Validated `$_GET` display-toggle shape for GalleryController::__invoke()
 * (replaces index.php) -- P26/SEC-40 Request DTO. `hasImageOrder`/
 * `hasDisplayParam` are kept separate from their own parsed
 * `validImageOrder`/`display` values since the original acts on presence
 * alone in one branch (the noindex meta flag / the unconditional redirect)
 * and on the parsed, validated value in another -- collapsing both into a
 * single nullable would lose that distinction. `display`'s own
 * `array_key_exists(..., ImageStdParams::get_defined_type_map())`
 * membership check stays at the call site (an app-specific image-config
 * lookup, not something a generic Request DTO should embed).
 */
final readonly class GalleryDisplayRequest
{
    private function __construct(
        public bool $hasImageOrder,
        public ?int $validImageOrder,
        public bool $hasDisplayParam,
        public ?string $display,
        public bool $hasCaddie,
        public bool $hasSlideshow,
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
        $image_order_raw = $source['image_order'] ?? null;
        $validImageOrder = (is_numeric($image_order_raw) && (int) $image_order_raw > 0) ? (int) $image_order_raw : null;

        $display_raw = $source['display'] ?? null;
        $display = is_string($display_raw) ? $display_raw : null;

        return new self(
            isset($source['image_order']),
            $validImageOrder,
            isset($source['display']),
            $display,
            isset($source['caddie']),
            isset($source['slideshow']),
        );
    }
}
