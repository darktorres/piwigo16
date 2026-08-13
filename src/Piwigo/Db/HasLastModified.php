<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Implemented by every entity mapping a `lastmodified TIMESTAMP` column
 * (`categories`, `groups`, `images`, `tags`, `user_infos`) so
 * {@see LastModifiedListener} can find it via a plain `instanceof` check --
 * no reflection/property-name sniffing.
 */
interface HasLastModified
{
    public function touchLastModified(SqlDateTime $now): void;
}
