<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Shared contract for positive-int row-identifier value objects.
 *
 * Tags the family (ImageId, UserId, CategoryId, …) for generic test scaffolding
 * and for narrow APIs that legitimately accept any row ID. Concrete code that
 * needs a specific identifier always types against the concrete class — `Image`
 * holds `ImageId`, not `NumericId`, so passing a `UserId` where an `ImageId`
 * was expected stays a compile-time error.
 */
interface NumericId extends \Stringable
{
    /**
     * @throws \InvalidArgumentException when $value is not positive
     */
    public static function from(int $value): static;

    public static function tryFrom(mixed $value): ?static;

    /**
     * Returns true only if $other is the same NumericId subtype with the same value.
     */
    public function equals(self $other): bool;
}
