<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Shared contract for shape-validated string value objects.
 *
 * Tags the family (Email, Username, LangCode, ThemeId, PluginId, …) for
 * generic test scaffolding and for narrow APIs that legitimately accept
 * any shape-validated string. Concrete code that needs a specific shape
 * always types against the concrete class — `User` holds `Email`, not
 * `StringVo`, so passing a `Username` where an `Email` was expected stays
 * a compile-time error.
 *
 * Each implementation validates its own shape at construction; downstream
 * code never re-validates.
 */
interface StringVo extends \Stringable
{
    /**
     * @throws \InvalidArgumentException when $value does not match the VO's shape
     */
    public static function from(string $value): static;

    public static function tryFrom(mixed $value): ?static;

    /**
     * Returns true only if $other is the same StringVo subtype with the same wrapped value.
     */
    public function equals(self $other): bool;
}
