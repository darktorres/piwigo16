<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\FormatId;

final class FormatIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return FormatId::class;
    }
}
