<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\PluginId;

final class PluginIdType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return PluginId::class;
    }
}
