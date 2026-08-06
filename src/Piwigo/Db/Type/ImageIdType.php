<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\ImageId;

final class ImageIdType extends AbstractNumericIdType
{
    #[Override]
    protected function voClass(): string
    {
        return ImageId::class;
    }
}
