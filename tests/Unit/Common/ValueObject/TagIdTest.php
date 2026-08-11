<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\NumericIdContract;

/**
 * @extends NumericIdContract<TagId>
 */
final class TagIdTest extends NumericIdContract
{
    #[Override]
    protected static function voClass(): string
    {
        return TagId::class;
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return CategoryId::class;
    }
}
