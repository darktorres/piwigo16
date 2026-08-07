<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SqlTime;

final class SqlTimeType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return SqlTime::class;
    }
}
