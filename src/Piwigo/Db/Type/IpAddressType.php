<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Piwigo\Common\ValueObject\IpAddress;

final class IpAddressType extends AbstractStringVoType
{
    #[\Override]
    protected function voClass(): string
    {
        return IpAddress::class;
    }
}
