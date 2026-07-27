<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Piwigo\Common\ValueObject\TagId;

final class TagIdType extends AbstractNumericIdType
{
    #[\Override]
    protected function voClass(): string
    {
        return TagId::class;
    }
}
