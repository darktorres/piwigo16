<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;
use RuntimeException;

/**
 * Finishes the identity tier: every column holding an opaque identifier
 * moves to `utf8mb4_bin`, the collation the schema already uses for that
 * purpose.
 *
 * The schema deliberately runs two collations, and that part is right:
 *
 *   utf8mb4_bin         identity -- slugs, filenames, logins, session tokens
 *   utf8mb4_unicode_ci  human text -- names, comments, descriptions
 *
 * Case-insensitive is the *correct* choice for the second tier: a search for
 * "sunset" should find a tag named "Sunset". Making the whole schema binary
 * would break that. The defect is that the first tier was applied to only 9
 * columns, leaving identifiers of exactly the same kind behind in the
 * second.
 *
 * The rule used here: a column belongs to the identity tier when its value
 * is an opaque or externally-defined identifier whose exact bytes are
 * significant -- a generated token, a cryptographic digest, a filesystem
 * name, or a lookup key -- rather than text a human reads. Discriminator
 * columns (`activity.object`, `history.section`, ...) are deliberately left
 * alone: their values are code-controlled literals, never user input, so the
 * change would be churn.
 *
 * **The strongest case is `user_auth_keys.auth_key`.**
 * `AuthRepository`/`ApiKeyRepository` match it with `WHERE uak.authKey =
 * :authKey` at six sites, and `SessionService::generateKey()` builds keys
 * from base64 with `+` and `/` stripped -- a *mixed-case* base62 alphabet.
 * Matching them case-insensitively collapses the effective alphabet from 62
 * to 36, so a 20-character key carries about 103 bits instead of the
 * intended 119. That is still far out of brute-force range, so this is a
 * real weakening rather than an exploitable hole -- but the generator picks
 * a mixed-case alphabet precisely because case is supposed to count, and the
 * schema was throwing that away.
 *
 * `images.md5sum` matters for a different reason: duplicate detection groups
 * on it, so under a case-insensitive collation two digests differing only in
 * hex case counted as the same photo.
 *
 * **PostgreSQL needs nothing.** Its default collation already compares text
 * byte-exactly for equality, so the two providers converge on this change
 * rather than diverging -- the same shape as dropping `unsigned` earlier.
 *
 * **Direction is safe.** Moving from case-insensitive to binary only ever
 * makes more values distinct, so no `UNIQUE` or primary key can begin to
 * collide. The reverse would not be safe, which is why `down()` refuses.
 */
final class Version20260815210000 extends AbstractMigration
{
    /**
     * The reviewable part of this migration: which columns are identity.
     * Their full definitions come from information_schema at run time, so
     * nullability, defaults and comments survive the MODIFY that would
     * otherwise silently drop them.
     *
     * @var list<array{string, string}>
     */
    private const array IDENTITY_COLUMNS = [
        // Credentials and generated tokens.
        ['user_auth_keys', 'auth_key'],
        ['user_auth_keys', 'apikey_secret'],
        ['user_infos', 'activation_key'],
        ['users', 'password'],
        ['search', 'search_uuid'],

        // Cryptographic digests and content fingerprints.
        ['images', 'md5sum'],
        ['audit_log', 'prev_hash'],
        ['audit_log', 'row_hash'],
        ['integrity_ignored_anomalies', 'anomaly_id'],

        // Filesystem names. `images.file` is already utf8mb4_bin; these are
        // the same kind of value and were not.
        ['images', 'path'],
        ['categories', 'dir'],

        // Extension identity -- directory names on disk. `plugins.id` is
        // already utf8mb4_bin, so the schema contradicted itself here: the
        // same plugin identifier was case-sensitive in one table and not in
        // another. user_infos.theme/language store a themes.id/languages.id
        // value and have to match what they reference.
        ['themes', 'id'],
        ['languages', 'id'],
        ['plugin_migrations', 'plugin_id'],
        ['extension_ignored_updates', 'extension_id'],
        ['user_infos', 'theme'],
        ['user_infos', 'language'],

        // Lookup keys.
        ['config', 'param'],
        ['derivative_size', 'name'],
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Move every identity column to utf8mb4_bin, the collation the schema already uses for identifiers';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        $database = $this->connection->fetchOne('SELECT DATABASE()');

        foreach (self::IDENTITY_COLUMNS as [$table, $column]) {
            $this->addSql($this->modifyToBinary($database, $table, $column));
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Going back to a case-insensitive collation could merge identifiers this migration made distinct, '
            . 'including authentication keys.'
        );
    }

    /**
     * Rebuilds one column's definition with `utf8mb4_bin`, preserving
     * everything else about it.
     *
     * `MODIFY` replaces the entire definition, so anything not restated is
     * lost -- the same trap the unsigned-removal migration documented.
     */
    private function modifyToBinary(mixed $database, string $table, string $column): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        );

        if ($row === false) {
            throw new RuntimeException(sprintf('%s.%s does not exist.', $table, $column));
        }

        $definition = $row['COLUMN_TYPE'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
        $definition .= $row['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL';

        // MariaDB reports a nullable column with no default as the literal
        // string "NULL" where MySQL reports null, and returns string
        // defaults already quoted -- the same divergence the unsigned-removal
        // migration documented.
        $default = $row['COLUMN_DEFAULT'];
        if ($default !== null && strtoupper($default) !== 'NULL') {
            $definition .= ' DEFAULT ' . (str_starts_with($default, "'") ? $default : "'" . str_replace("'", "''", $default) . "'");
        }

        $extra = strtoupper($row['EXTRA'] ?? '');
        if (str_contains($extra, 'AUTO_INCREMENT')) {
            $definition .= ' AUTO_INCREMENT';
        }

        $comment = $row['COLUMN_COMMENT'];
        if ($comment !== '') {
            $definition .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
        }

        return sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $definition);
    }
}
