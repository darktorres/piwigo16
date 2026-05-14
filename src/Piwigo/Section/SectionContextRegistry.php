<?php

declare(strict_types=1);

namespace Piwigo\Section;

final class SectionContextRegistry
{
    private static ?SectionContext $instance = null;

    public static function set(SectionContext $ctx): void
    {
        self::$instance = $ctx;
    }

    public static function current(): SectionContext
    {
        return self::$instance ?? new SectionContext();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
