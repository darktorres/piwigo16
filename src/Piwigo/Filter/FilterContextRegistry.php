<?php

declare(strict_types=1);

namespace Piwigo\Filter;

final class FilterContextRegistry
{
    private static ?FilterContext $instance = null;

    public static function set(FilterContext $ctx): void
    {
        self::$instance = $ctx;
    }

    public static function current(): FilterContext
    {
        return self::$instance ?? new FilterContext();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
