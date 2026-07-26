<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

/**
 * One registered EventDispatcher handler slot. `$function` stays a real
 * PHP `callable` shape rather than the native `callable` type hint --
 * see EventDispatcher's own docblock on why eager callability validation
 * at registration time is wrong here.
 */
final readonly class EventHandler
{
    /**
     * @param array{0: object|string, 1: string}|object|string $function
     */
    public function __construct(
        public array|object|string $function,
        public ?string $includePath,
    ) {}
}
