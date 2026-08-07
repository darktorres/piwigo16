<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\Username;

final class UsernameType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return Username::class;
    }
}
