<?php

declare(strict_types=1);

namespace Piwigo\Ws\OpenApi;

/**
 * Immutable value object wrapping an OpenAPI 3.1 document.
 */
final class OpenApiDocument
{
    /** @param array<mixed> $spec */
    public function __construct(private readonly array $spec)
    {
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->spec;
    }

    public function toJson(): string
    {
        $json = json_encode(
            $this->spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $json !== false ? $json : '{}';
    }
}
