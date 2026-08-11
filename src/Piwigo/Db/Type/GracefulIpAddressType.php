<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Override;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\StringVo;

/**
 * For `history.IP`/`rate.anonymous_id`-shaped columns (`NOT NULL DEFAULT
 * ''`, empty string is the real "no IP recorded" sentinel) -- see
 * {@see AbstractGracefulEmptyStringVoType}. `activity.ip_address`/
 * `audit_log.ip_address` are genuinely nullable columns instead, and
 * stay on the strict {@see IpAddressType}.
 */
final class GracefulIpAddressType extends AbstractGracefulEmptyStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return IpAddress::class;
    }

    /**
     * `history.IP` is `CHAR(39)`, not `VARCHAR` (`rate.anonymous_id`,
     * the same "NOT NULL DEFAULT ''" family, IS `VARCHAR` -- no padding)
     * -- the driver returns
     * a real, valid IP right-padded with spaces to the column's fixed
     * width, which `filter_var()` then rejects outright. `rtrim()`
     * first, so both a real IP and the column's own `''` sentinel
     * (itself padded to 39 spaces too) are recognized correctly.
     */
    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringVo
    {
        return parent::convertToPHPValue(is_string($value) ? rtrim($value) : $value, $platform);
    }
}
