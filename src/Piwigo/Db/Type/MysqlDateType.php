<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\MysqlDate;

final class MysqlDateType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return MysqlDate::class;
    }
}
