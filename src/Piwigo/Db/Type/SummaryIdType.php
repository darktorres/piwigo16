<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SummaryId;

final class SummaryIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return SummaryId::class;
    }
}
