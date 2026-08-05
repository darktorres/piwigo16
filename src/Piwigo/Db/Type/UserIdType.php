<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\UserId;

final class UserIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return UserId::class;
    }
}
