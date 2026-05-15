<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for legacy `get_tag_alt_names` (dispatch).
 *
 * New in 2.4
 *
 * Dispatched from: src/Piwigo/Admin/Tag/TagAdminService.php
 */
final readonly class GetTagAltNames
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
        public string $rawName,
    ) {
    }

    /**
     * @param array<mixed> $value
     */
    public function withValue(array $value): self
    {
        return new self($value, $this->rawName);
    }
}
