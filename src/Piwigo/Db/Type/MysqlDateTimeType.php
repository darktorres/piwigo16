<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\MysqlDateTime;

final class MysqlDateTimeType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return MysqlDateTime::class;
    }
}
