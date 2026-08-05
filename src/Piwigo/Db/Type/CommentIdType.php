<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\CommentId;

final class CommentIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return CommentId::class;
    }
}
