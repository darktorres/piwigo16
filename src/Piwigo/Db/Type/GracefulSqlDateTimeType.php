<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * For `images.date_creation`/`images.date_available` -- nullable columns
 * with a real, confirmed-live pre-existing MySQL zero-date
 * ('0000-00-00 00:00:00') sentinel in legacy EXIF/IPTC-synced data (see
 * {@see \Piwigo\Image\ImageEntity}'s own docblock;
 * `Metadata\MetadataService::getSyncExifData()` already contains
 * production code normalizing this sentinel to `null` on new writes, but
 * existing rows aren't guaranteed clean). Unlike
 * {@see AbstractGracefulEmptyStringVoType}, `null` is already a distinct,
 * legitimate DB value for these columns (both are genuinely nullable) --
 * only an unparseable *non-null* string degrades to PHP `null` on read,
 * instead of throwing. Write side stays exactly as strict as
 * {@see AbstractStringVoType}'s own default: the one real write call site
 * ({@see \Piwigo\Image\ImageRepository::updateDescriptiveFields()}) always
 * converts through `SqlDateTime::from()` before assignment, so this
 * sentinel can never be written again.
 */
final class GracefulSqlDateTimeType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return SqlDateTime::class;
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?SqlDateTime
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected string from the DB driver, got %s', get_debug_type($value)));
        }

        return SqlDateTime::tryFrom($value);
    }
}
