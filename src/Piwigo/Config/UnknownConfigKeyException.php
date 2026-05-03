<?php

declare(strict_types=1);

namespace Piwigo\Config;

use RuntimeException;

/**
 * Thrown when one of the private typed-getter helpers (getString, getInt,
 * getBool, getFloat, getArray) is called with a key that is not present in
 * Config::SCHEMA. This catches typos in hand-written custom accessors and any
 * accidental drift from the source-of-truth registry.
 *
 * Dynamic / parametric keys (per-block menu config, *_running semaphores,
 * flip_picture_ext caches, etc.) are read via Config::raw() which bypasses
 * SCHEMA validation by design.
 */
final class UnknownConfigKeyException extends RuntimeException
{
    public function __construct(string $key, string $callee)
    {
        parent::__construct(
            "Config::{$callee}('{$key}') called with key not in Config::SCHEMA. "
            . 'Either add the key to SCHEMA + run tools/build-config-accessors.php, '
            . 'or use Config::raw() if the key is genuinely dynamic.'
        );
    }
}
