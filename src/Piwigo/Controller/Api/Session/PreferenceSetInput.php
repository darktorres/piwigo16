<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

/**
 * `PUT /api/v1/session/preferences/{param}` body DTO -- carries `value`/
 * `isJson` (`param` itself comes from the route).
 */
final readonly class PreferenceSetInput
{
    public function __construct(
        public ?string $value,
        public bool $isJson,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $value = $raw['value'] ?? null;
        $isJson = $raw['isJson'] ?? null;

        return new self(
            value: is_string($value) ? $value : null,
            isJson: is_bool($isJson) ? $isJson : false,
        );
    }
}
