<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\SiteId;

final class SiteIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return SiteId::class;
    }
}
