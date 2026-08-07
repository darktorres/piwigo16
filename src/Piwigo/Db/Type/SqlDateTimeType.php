<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SqlDateTime;

final class SqlDateTimeType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return SqlDateTime::class;
    }
}
