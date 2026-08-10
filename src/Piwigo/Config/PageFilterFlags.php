<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A page's own tag/date filter UI flags. Genuinely open-ended -- callers
 * (see Piwigo\Core\PageFilterHelper::getFilterPageValue()) look up flags
 * by an arbitrary name, not just the 3 well-known Piwigo-core ones
 * (`used`/`cancel`/`add_notes`), so this wraps a flag map rather than
 * fixed named fields.
 */
final readonly class PageFilterFlags
{
    /**
     * @param array<string, bool> $flags
     */
    public function __construct(
        public array $flags,
    ) {}

    /**
     * @param array<mixed> $entry
     */
    public static function fromArray(array $entry): self
    {
        $result = [];
        foreach ($entry as $key => $val) {
            if (is_string($key) && is_bool($val)) {
                $result[$key] = $val;
            }
        }

        return new self(flags: $result);
    }
}
