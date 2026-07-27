<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\NumericIdContract;

/** @extends NumericIdContract<GroupId> */
final class GroupIdTest extends NumericIdContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return GroupId::class;
    }
}
