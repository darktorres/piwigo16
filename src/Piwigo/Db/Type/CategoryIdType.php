<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Piwigo\Common\ValueObject\CategoryId;

final class CategoryIdType extends AbstractNumericIdType
{
    #[\Override]
    protected function voClass(): string
    {
        return CategoryId::class;
    }
}
