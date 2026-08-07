<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\Permalink;

final class PermalinkType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return Permalink::class;
    }
}
