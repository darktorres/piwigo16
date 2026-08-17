<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

/**
 * `POST /api/v1/extensions/{type}/{id}/actions/update` body DTO -- carries
 * `revision`; `type`/`id` come from the route, not the body.
 */
final readonly class ExtensionUpdateInput
{
    public function __construct(
        public string $revision,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $revision = $raw['revision'] ?? null;

        return new self(
            revision: is_string($revision) ? $revision : '',
        );
    }
}
