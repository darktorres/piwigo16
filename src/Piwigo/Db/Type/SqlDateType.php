<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SqlDate;

final class SqlDateType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return SqlDate::class;
    }
}
