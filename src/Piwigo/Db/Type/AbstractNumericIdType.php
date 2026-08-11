<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\NumericId;

/**
 * Shared conversion logic for a `NumericId` VO mapped directly onto an int
 * column. Extends the abstract `Type` base, not `IntegerType` --
 * `IntegerType::convertToPHPValue()` declares return type `?int`, and PHP
 * requires covariant overrides, so a subclass can't narrow that to
 * `?GroupId`. The base `Type` class declares it `mixed`, which is safely
 * narrowable.
 *
 * `convertToDatabaseValue()` is strict (VO-only) on purpose: every real
 * caller across every domain -- including ones outside this VO's own
 * domain, e.g. `Category\CategoryRepository` querying `GroupAccessEntity`
 * directly -- wraps its raw ints into the VO before touching a
 * custom-typed field. A silent int/VO dual-acceptance here would quietly
 * defeat the point of typing the field at all.
 */
abstract class AbstractNumericIdType extends Type
{
    /**
     * @return class-string<NumericId>
     */
    abstract protected function voClass(): string;

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?NumericId
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected int or string from the DB driver, got %s', get_debug_type($value)));
        }

        $class = $this->voClass();

        return $class::from((int) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof NumericId) {
            // Real check, not assert() -- this project runs zend.assertions=-1
            // everywhere, so assert() is a silent no-op here.
            throw new InvalidArgumentException(sprintf('Expected %s, got %s', NumericId::class, get_debug_type($value)));
        }

        // NumericId is an interface -- PHP interfaces can't declare
        // properties, so ->value isn't part of its statically-known
        // contract even though every real implementation has one. Every
        // implementation's __toString() is exactly `(string) $this->value`
        // (Common\ValueObject's own established convention), so round-
        // tripping through the guaranteed Stringable contract is the
        // type-safe way to reach it here.
        return (int) (string) $value;
    }

    #[Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    #[Override]
    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }
}
