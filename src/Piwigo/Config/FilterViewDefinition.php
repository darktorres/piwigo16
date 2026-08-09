<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A single search-filter's access level and default-on state.
 */
final readonly class FilterViewDefinition
{
    public function __construct(
        public string $access,
        public bool $default,
    ) {}

    /**
     * Returns null on a malformed shape rather than a guessed default --
     * the correct per-key fallback (e.g. 'words' vs 'tags') is only known
     * by CurrentConfig::DEFAULT_FILTERS_VIEWS, not by this class.
     *
     * @param array<mixed> $entry
     */
    public static function tryFromArray(array $entry): ?self
    {
        $access = $entry['access'] ?? null;
        $default = $entry['default'] ?? null;

        return is_string($access) && is_bool($default)
            ? new self(access: $access, default: $default)
            : null;
    }

    /**
     * @return array{access: string, default: bool}
     */
    public function toArray(): array
    {
        return [
            'access' => $this->access,
            'default' => $this->default,
        ];
    }
}
