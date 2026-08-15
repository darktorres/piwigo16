<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Typed descriptor for a single WS method parameter, passed to
 * `MethodDefinition::$params`. Maps 1:1 onto the array-shape param
 * definitions `Server::addMethod()` already accepts (`type`/`flags`/
 * `default`/`maxValue`) -- `Server::register()` converts a list of these
 * into that same shape, so the existing signature-validation loop in
 * `Server::invoke()` runs unchanged for handler-based methods too.
 *
 * Use the named constructors:
 *   ParamDefinition::required('comment_id', WsParamType::INT | WsParamType::POSITIVE)
 *   ParamDefinition::optional('per_page', 10, WsParamType::INT | WsParamType::POSITIVE)
 */
final readonly class ParamDefinition
{
    private function __construct(
        public string $name,
        public int $type,
        public int $flags,
        public mixed $default,
        public bool $hasDefault,
        public int|float|null $maxValue,
        public string $info,
    ) {}

    public static function required(
        string $name,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags, null, false, $maxValue, $info);
    }

    public static function optional(
        string $name,
        mixed $default = null,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags | WsParamFlag::OPTIONAL, $default, true, $maxValue, $info);
    }

    /**
     * Optional param with no default value -- leaves the param absent
     * from $params if not provided (rather than injecting null). Use
     * when the handler checks isset($params['name']).
     */
    public static function optionalFlag(
        string $name,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags | WsParamFlag::OPTIONAL, null, false, $maxValue, $info);
    }
}
