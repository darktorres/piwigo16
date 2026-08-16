<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;
use RuntimeException;

/**
 * Declares `plugin_migrations.plugin_id` -> `plugins.id`.
 *
 * Depends on the identity-tier collation alignment immediately before it:
 * MySQL refuses a foreign key between character columns whose collations
 * differ, and these did (`plugins.id` was already `utf8mb4_bin`, the ledger
 * was `utf8mb4_unicode_ci`). That mismatch is what made this relationship
 * unrepresentable rather than merely undeclared.
 *
 * **`ON DELETE RESTRICT`, not a cascade.** A `plugins` row's existence means
 * "installed" (`ExtensionLifecycle`'s own docblock), and uninstalling deletes
 * it. A cascade would therefore drop a plugin's migration ledger on every
 * uninstall, silently, so a reinstall would re-run migrations it had already
 * applied. `RESTRICT` forces that to be a decision in code instead:
 * `PluginRegistry::uninstall()` clears the ledger explicitly, in the same
 * transaction as the row it belongs to. `SET NULL` is unavailable -- the
 * column is half of this table's primary key.
 *
 * No index is added: `plugin_id` is the leading column of that primary key,
 * so the constraint is already covered on both engines.
 */
final class Version20260815220000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Constrain plugin_migrations.plugin_id to plugins.id, with RESTRICT so an uninstall cannot silently drop the ledger';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->guardAgainstOrphans();

        $this->addSql(
            'ALTER TABLE plugin_migrations ADD CONSTRAINT fk_plugin_migrations_plugin_id '
            . 'FOREIGN KEY (plugin_id) REFERENCES plugins (id) ON DELETE RESTRICT'
        );
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping this constraint would let an uninstall orphan its migration ledger again.'
        );
    }

    /**
     * A ledger row for a plugin that is no longer installed is exactly what
     * `RESTRICT` forbids, and it is the likeliest way this migration fails on
     * a real installation: nothing previously stopped an uninstall from
     * leaving its history behind.
     *
     * Reported, not deleted. Which of those rows are worth keeping is a
     * judgement about that installation's own history, and this migration has
     * no mandate to make it.
     */
    private function guardAgainstOrphans(): void
    {
        $orphans = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('plugin_migrations', 'ledger')
            ->leftJoin('ledger', 'plugins', 'plugin', 'plugin.id = ledger.plugin_id')
            ->where('plugin.id IS NULL')
            ->executeQuery()
            ->fetchOne();

        if (is_numeric($orphans) && (int) $orphans > 0) {
            throw new RuntimeException(sprintf(
                'plugin_migrations holds %d row(s) for plugins that are no longer installed. '
                . 'Delete them, or reinstall those plugins, then re-run this migration.',
                (int) $orphans,
            ));
        }
    }
}
