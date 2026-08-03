<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET['method']` for MaintenanceSysPageRenderer::render() (the
 * "sys" tab of page slug "maintenance") -- P26/SEC-40 Request DTO. The
 * only real value this page acts on is the exact literal
 * `pwg.activity_sys.getList` (an equality check, not a pattern) -- anything
 * else falls through to the normal HTML render, so there's nothing for
 * InputValidator's pattern validation to add here.
 */
final readonly class MaintenanceSysMethodRequest
{
    private function __construct(
        public bool $isActivitySysGetList,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        return new self(($source['method'] ?? null) === 'pwg.activity_sys.getList');
    }
}
