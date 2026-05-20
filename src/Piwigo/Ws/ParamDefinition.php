<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Typed descriptor for a single WS method parameter.
 *
 * Use the named constructors:
 *   ParamDefinition::required('image_id', WsType::Id->value)
 *   ParamDefinition::optional('per_page', 100, WsType::Int->value | WsType::Positive->value, maxValue: 500)
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
    ) {
    }

    public static function required(
        string $name,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags, null, false, $maxValue, $info);
    }

    /**
     * @param bool|int|null|string|string[] $default
     *
     * @psalm-param bool|int|list<string>|null|string $default
     */
    public static function optional(
        string $name,
        array|string|int|bool|null $default = null,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags | WsParam::Optional->value, $default, true, $maxValue, $info);
    }

    /**
     * Optional param with no default value.
     * Unlike optional(), this leaves the param absent from $params if not provided
     * (rather than injecting null). Use when the handler checks isset($params['name']).
     */
    public static function optionalFlag(
        string $name,
        int $type = 0,
        int $flags = 0,
        string $info = '',
        int|float|null $maxValue = null,
    ): self {
        return new self($name, $type, $flags | WsParam::Optional->value, null, false, $maxValue, $info);
    }

}
