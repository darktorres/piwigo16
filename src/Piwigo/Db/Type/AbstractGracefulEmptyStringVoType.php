<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Override;
use Piwigo\Common\ValueObject\StringVo;

/**
 * Sibling of {@see AbstractStringVoType} for a `NOT NULL DEFAULT ''`
 * column whose empty string IS the "no real value" sentinel
 * ({@see \Piwigo\Common\ValueObject\IpAddress}'s own docblock names
 * `history.IP`/`rate.anonymous_id` as this exact pattern). Unlike the
 * strict base, `''` round-trips to/from PHP `null` instead of throwing --
 * a non-empty but still-malformed value still throws, since that's
 * genuine corrupt data, not the known sentinel.
 */
abstract class AbstractGracefulEmptyStringVoType extends AbstractStringVoType
{
    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringVo
    {
        if ($value === '') {
            return null;
        }

        return parent::convertToPHPValue($value, $platform);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return '';
        }

        return parent::convertToDatabaseValue($value, $platform);
    }
}
