<?php

declare(strict_types=1);

namespace Piwigo\Picture;

final class PictureContextRegistry
{
    private static ?PictureContext $instance = null;

    public static function set(PictureContext $ctx): void
    {
        self::$instance = $ctx;
    }

    public static function current(): PictureContext
    {
        return self::$instance ?? new PictureContext();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
