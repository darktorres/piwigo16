<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

/**
 * `POST /api/v1/plugins/{id}/actions/perform` body DTO -- mirrors
 * `Ws\Extensions\PluginsPerformActionParams`'s own `action` field
 * (install, activate, deactivate, uninstall, delete).
 */
final readonly class PluginActionInput
{
    public function __construct(
        public string $action,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $action = $raw['action'] ?? null;

        return new self(
            action: is_string($action) ? $action : '',
        );
    }
}
