<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Core\Kernel;

/**
 * P7 boot skeleton. Grows into the full boot orchestrator (exception handler
 * registration, superglobal sanitization, config/DB/user/plugin/template
 * init) incrementally as P8, P13, P16 and P17-23 land their pieces. For now
 * it only proves the new Kernel boots on every real request — index.php
 * calls this once, before all existing legacy request handling runs
 * unchanged.
 */
final class CommonBootstrap
{
    public static function run(): void
    {
        Kernel::boot();
    }
}
