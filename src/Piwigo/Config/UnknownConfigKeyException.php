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
 * Dynamic / parametric keys (plugin-persisted rows, etc.) go through
 * ConfigService::confGetParam, which reads Config::all() directly without
 * SCHEMA validation.
 */
final class UnknownConfigKeyException extends RuntimeException
{
    public function __construct(string $key, string $callee)
    {
        parent::__construct(
            "Config::{$callee}('{$key}') called with key not in Config::SCHEMA. "
            . 'Add the SCHEMA entry and its matching typed accessor in Config.php, '
            . 'or use ConfigService::confGetParam() if the key is genuinely dynamic.'
        );
    }
}
