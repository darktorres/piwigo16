<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\RelPath;

final class RelPathType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return RelPath::class;
    }
}
