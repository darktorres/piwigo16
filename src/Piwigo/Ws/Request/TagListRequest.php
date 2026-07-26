<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated `$_REQUEST['tag_list']` shape for PwgImages::setInfo() --
 * P27/SEC-40 Request DTO. Temporary batch-manager-unit-mode-only field,
 * not meant for external API use (see the call site's own docblock) --
 * `items` retains every raw element, even non-string ones, matching the
 * original's own per-element `is_string($tag_candidate) ? $tag_candidate
 * : ''` normalization (a non-string element still contributes one empty
 * entry, it isn't dropped), which the call site still applies itself.
 */
final readonly class TagListRequest
{
    /**
     * @param array<int|string, mixed> $items
     */
    private function __construct(
        public bool $present,
        public array $items,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $request
     */
    public static function fromArray(array $request): self
    {
        $tag_list_raw = $request['tag_list'] ?? null;
        $present = isset($request['tag_list']) && is_array($tag_list_raw);

        return new self($present, $present ? $tag_list_raw : []);
    }
}
