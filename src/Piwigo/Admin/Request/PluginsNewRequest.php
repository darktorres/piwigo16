<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET` shape for PluginsNewPageRenderer::render() (the "new"
 * tab of the "plugins" page slug) -- P27/SEC-40 Request DTO.
 *
 * `revision`/`extension` collapse the original's combined
 * `isset(...) and isset(...) and is_string(...) and is_string(...)` gate
 * into two nullable strings -- the caller's own `!== null` checks on both
 * reproduce that exact combined condition.
 *
 * `installStatusPresent`/`installStatus` split the original's single
 * `isset($_GET['installstatus'])` (raw switch on whatever's there) into a
 * presence flag plus a normalized string: a present-but-non-string value
 * normalizes to `''`, which still falls through to the switch's `default:`
 * branch exactly like the original's `is_string(...) ? ... : ''` cast did.
 */
final readonly class PluginsNewRequest
{
    private function __construct(
        public ?string $revision,
        public ?string $extension,
        public bool $installStatusPresent,
        public string $installStatus,
        public ?string $pluginId,
        public bool $betaTest,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        $revision = $get['revision'] ?? null;
        $extension = $get['extension'] ?? null;

        $installStatusRaw = $get['installstatus'] ?? null;

        $pluginId = $get['plugin_id'] ?? null;

        $betaTest = $get['beta-test'] ?? null;

        return new self(
            is_string($revision) ? $revision : null,
            is_string($extension) ? $extension : null,
            isset($get['installstatus']),
            is_string($installStatusRaw) ? $installStatusRaw : '',
            is_string($pluginId) ? $pluginId : null,
            $betaTest === 'true',
        );
    }
}
