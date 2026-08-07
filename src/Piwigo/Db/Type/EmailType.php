<?php

declare(strict_types=1);

namespace Piwigo\Db\Type;

use Override;
use Piwigo\Common\ValueObject\Email;

final class EmailType extends AbstractStringVoType
{
    #[Override]
    protected function voClass(): string
    {
        return Email::class;
    }
}
