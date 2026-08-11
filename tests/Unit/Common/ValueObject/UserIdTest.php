<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\NumericIdContract;

/**
 * @extends NumericIdContract<UserId>
 */
final class UserIdTest extends NumericIdContract
{
    #[Override]
    protected static function voClass(): string
    {
        return UserId::class;
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return CategoryId::class;
    }
}
