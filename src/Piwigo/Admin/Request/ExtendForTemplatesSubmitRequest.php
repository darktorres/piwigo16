<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_POST` shape for ExtendForTemplatesPageRenderer::render()'s
 * save form (page slug "extend_for_templates").
 * The 4 fields are parallel arrays (`reptpl[]`/`original[]`/`url[]`/
 * `bound[]`), indexed by row position -- preserved as-is (not restructured
 * into a list of row objects) since the consuming loop's own
 * `while (isset($post_reptpl[$i]))` positional-index walk is unchanged.
 * Each element is validated as a string only at the point of use (matching
 * the original), not here, since a malformed row is simply skipped rather
 * than being a hard rejection.
 */
final readonly class ExtendForTemplatesSubmitRequest
{
    /**
     * @param array<array-key, mixed> $reptpl
     * @param array<array-key, mixed> $original
     * @param array<array-key, mixed> $url
     * @param array<array-key, mixed> $bound
     */
    private function __construct(
        public bool $isSubmitted,
        public array $reptpl,
        public array $original,
        public array $url,
        public array $bound,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<array-key, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        return new self(
            isset($post['submit']),
            self::arrayOrEmpty($post, 'reptpl'),
            self::arrayOrEmpty($post, 'original'),
            self::arrayOrEmpty($post, 'url'),
            self::arrayOrEmpty($post, 'bound'),
        );
    }

    /**
     * @param array<array-key, mixed> $post
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(array $post, string $key): array
    {
        $value = $post[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
