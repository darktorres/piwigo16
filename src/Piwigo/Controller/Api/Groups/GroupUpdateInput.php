<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

/**
 * `PATCH /api/v1/groups/{id}` body DTO -- `name`/`is_default` are optional
 * ("leave a field blank to keep the current value"): a genuinely absent
 * JSON key means "don't touch this field", not "clear it" -- both
 * properties stay nullable rather than defaulting to a concrete value the
 * way `GroupCreateInput` does.
 */
final readonly class GroupUpdateInput
{
    public function __construct(
        public ?string $name,
        public ?bool $isDefault,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        $isDefault = $raw['isDefault'] ?? null;

        return new self(
            name: is_string($name) ? $name : null,
            isDefault: is_bool($isDefault) ? $isDefault : null,
        );
    }
}
