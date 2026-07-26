<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET['action']` for TagsPageRenderer::render() (page slug
 * "tags") -- P27/SEC-40 Request DTO. The only real value this page acts on
 * is the exact literal `delete_orphans` (an equality check, not a
 * pattern) -- anything else is treated identically to an absent action, so
 * there's nothing for InputValidator's pattern validation to add here; this
 * DTO exists to name the concept and remove the raw superglobal read, not
 * to add new rejection behavior.
 */
final readonly class TagsActionRequest
{
    private function __construct(
        public bool $isDeleteOrphans,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        return new self(($source['action'] ?? null) === 'delete_orphans');
    }
}
