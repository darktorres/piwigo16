<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\ActivityId;

final class ActivityIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return ActivityId::class;
    }
}
