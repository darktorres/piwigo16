<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance\Request;

/**
 * Validated `$_GET['action']` for `MaintenanceActionsPageRenderer::render()`/
 * `MaintenanceEnvPageRenderer::render()` (the "actions"/"env" tabs of page
 * slug "maintenance") -- P27/SEC-40 Request DTO, shared by both since they
 * had the exact same inline `$_GET['action']` read duplicated verbatim.
 * `MaintenanceActionDispatcher::dispatch()`'s own switch already treats any
 * unrecognized action as a safe no-op (`default:` case), so there's no
 * pattern to validate against here -- this DTO exists to remove the
 * duplicated raw superglobal read and name the concept.
 */
final readonly class MaintenanceActionRequest
{
    private function __construct(
        public string $action,
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
        $action_raw = $source['action'] ?? null;

        return new self(is_string($action_raw) ? $action_raw : '');
    }
}
