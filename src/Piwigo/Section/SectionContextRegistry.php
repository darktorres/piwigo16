<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Per-request holder for the current SectionContext -- self-managed
 * singleton (current()/set()/reset()), same shape as
 * Piwigo\Storage\StorageRegistry/Piwigo\Session\SessionService.
 */
final class SectionContextRegistry
{
    private static ?SectionContext $current = null;

    public static function set(SectionContext $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?SectionContext
    {
        return self::$current;
    }

    /**
     * Tests only -- production code never needs to clear this mid-request.
     */
    public static function reset(): void
    {
        self::$current = null;
    }
}
