<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\StringVo;

/**
 * Shared conversion logic for a `StringVo` VO mapped directly onto a
 * string column -- the `StringVo` counterpart to `AbstractNumericIdType`.
 * Extends the abstract `Type` base, not `StringType`, for the same
 * covariant-return reason documented on `AbstractNumericIdType`:
 * `StringType::convertToPHPValue()` declares `?string`, which a subclass
 * can't narrow to e.g. `?IpAddress`.
 *
 * `convertToDatabaseValue()` is strict (VO-only) on purpose, same
 * rationale as `AbstractNumericIdType`'s own.
 */
abstract class AbstractStringVoType extends Type
{
    /**
     * @return class-string<StringVo>
     */
    abstract protected function voClass(): string;

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringVo
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected string from the DB driver, got %s', get_debug_type($value)));
        }

        $class = $this->voClass();

        return $class::from($value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof StringVo) {
            // Real check, not assert() -- this project runs zend.assertions=-1
            // everywhere, so assert() is a silent no-op here.
            throw new InvalidArgumentException(sprintf('Expected %s, got %s', StringVo::class, get_debug_type($value)));
        }

        return (string) $value;
    }

    #[Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[Override]
    public function getBindingType(): ParameterType
    {
        return ParameterType::STRING;
    }
}
