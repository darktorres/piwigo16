<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\NumericIdContract;

/** @extends NumericIdContract<CommentId> */
final class CommentIdTest extends NumericIdContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return CommentId::class;
    }
}
