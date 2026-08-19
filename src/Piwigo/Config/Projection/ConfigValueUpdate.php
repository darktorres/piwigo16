<?php

declare(strict_types=1);

namespace Piwigo\Config\Projection;

/**
 * {@see \Piwigo\Config\ConfigRepository::massUpdateValues()}'s own
 * `config` update row. `$value` stays `mixed` -- matches
 * `Piwigo\Config\CurrentConfig`'s own accepted per-key-arbitrary-value
 * reasoning (the `config` table stores a genuinely heterogeneous set of
 * param values); only the pair itself needed a name.
 */
final readonly class ConfigValueUpdate
{
    public function __construct(
        public string $param,
        public mixed $value,
    ) {}

    /**
     * @return array{param: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'param' => $this->param,
            'value' => $this->value,
        ];
    }
}
