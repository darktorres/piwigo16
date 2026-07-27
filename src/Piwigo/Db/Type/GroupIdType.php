<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Piwigo\Common\ValueObject\GroupId;

final class GroupIdType extends AbstractNumericIdType
{
    #[\Override]
    protected function voClass(): string
    {
        return GroupId::class;
    }
}
