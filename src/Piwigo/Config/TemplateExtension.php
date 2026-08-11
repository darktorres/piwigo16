<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A single template-extension override -- the [handle, param, theme]
 * 3-tuple Template::setExtents() destructures positionally.
 */
final readonly class TemplateExtension
{
    public function __construct(
        public string $handle,
        public string $param,
        public string $theme,
    ) {}

    /**
     * @param array<mixed> $entry
     */
    public static function tryFromArray(array $entry): ?self
    {
        $handle = $entry[0] ?? null;
        $param = $entry[1] ?? null;
        $theme = $entry[2] ?? null;

        if ((! is_string($handle) && ! is_int($handle)) || ! is_scalar($param) || ! is_scalar($theme)) {
            return null;
        }

        return new self(
            handle: (string) $handle,
            param: (string) $param,
            theme: (string) $theme,
        );
    }
}
