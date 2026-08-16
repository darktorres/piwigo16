<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SearchId;

final class SearchIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return SearchId::class;
    }
}
