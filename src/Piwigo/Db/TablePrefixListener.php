<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Prepends the configured DbCredentials prefix to every ORM entity's table
 * name at metadata load time, since PHP attributes can't embed a runtime
 * config value
 * directly (#[ORM\Table(name: ...)] must be a compile-time literal).
 * Entities declare their bare table name (e.g. 'config'); this listener
 * makes the actual SQL target 'piwigo_config' (or whatever db_prefix is
 * configured to). Mirrors the doc's own "prefix applied by a Doctrine
 * naming strategy" note -- implemented as a loadClassMetadata listener
 * since that's the standard Doctrine mechanism for this, not a custom
 * NamingStrategy (which only affects generated names, not attribute-
 * declared ones).
 */
final class TablePrefixListener
{
    public function __construct(
        private readonly DbCredentials $dbCredentials,
    ) {}

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $prefix = $this->dbCredentials->prefix;
        $tableName = $metadata->getTableName();

        if ($prefix === '' || str_starts_with($tableName, $prefix)) {
            return;
        }

        $metadata->setPrimaryTable([
            'name' => $prefix . $tableName,
        ]);
    }
}
