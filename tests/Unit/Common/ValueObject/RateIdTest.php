<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\RateId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\NumericIdContract;

/** @extends NumericIdContract<RateId> */
final class RateIdTest extends NumericIdContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return RateId::class;
    }
}
