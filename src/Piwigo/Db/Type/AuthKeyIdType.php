<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\AuthKeyId;

final class AuthKeyIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return AuthKeyId::class;
    }
}
