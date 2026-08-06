<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\Md5Sum;

final class Md5SumType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return Md5Sum::class;
    }
}
