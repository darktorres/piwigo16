<?php

declare(strict_types=1);

namespace Piwigo\Ws\OpenApi;

/**
 * Enriches a WS handler method with OpenAPI metadata.
 * Place this attribute on the __invoke / handler function to override the
 * auto-generated summary, tags, and (future) response schema.
 *
 * Usage:
 *   #[ApiMethod(summary: 'Returns gallery version string.', tags: ['info'])]
 *   public function getVersion(...): string { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final class ApiMethod
{
    /**
     * @param string   $summary       Short one-line description (overrides addMethod description).
     * @param string   $responseClass FQCN of a typed response DTO (for future schema generation).
     * @param string[] $tags          OpenAPI tag names for grouping in Swagger UI.
     */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $responseClass = '',
        public readonly array $tags = [],
    ) {
    }
}
