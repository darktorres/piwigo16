<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use InvalidArgumentException;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Permission\SqlCondition;

/**
 * Per-extension-namespaced raw-DB accessor handed out by `ExtensionContext::
 * db()` -- the framework-level gap `docs/porting-open-items.md` names as
 * blocking any future plugin/theme with its own custom table (grounded in
 * real, traced callers: `../piwigo16-plugins/BanIP_13.0.a/admin.php`'s own
 * row-addressable, individually-edited/deleted-by-id `ip_ban` table with a
 * `LIKE`-based range-prefix match, and `AdditionalPages_14.a/maintain.inc.php`'s
 * own `CREATE TABLE IF NOT EXISTS <prefix>additionalpages`).
 *
 * Deliberately NOT a raw `Connection` passthrough, and deliberately has no
 * method accepting a table name or raw `FROM`/`JOIN` target from the
 * caller -- every method here resolves the table internally via
 * {@see tableName()}, so an extension is structurally unable to name any
 * table but its own, the same "narrow, purpose-built, grounded in a real
 * caller" discipline as every other `Facade\*` class, applied to a
 * different axis (which *tables* are reachable, not which *operations*
 * are). This is why {@see update()}/{@see delete()}/{@see select()}/
 * {@see count()} take a {@see SqlCondition} rather than a raw SQL string:
 * real legacy queries need more than flat equality (`BanIP`'s own `WHERE ip
 * LIKE "192.168.%"` range match), but `SqlCondition` still can't reference
 * a second table the way an arbitrary string could.
 *
 * `insert()`/`update()`/`delete()` build on `Connection`'s own already-
 * portable, already-parameterized convenience methods/`QueryBuilder`
 * rather than hand-rolled SQL -- no new query-construction logic to
 * maintain. `createTable()`/`dropTable()`/`hasTable()` go through
 * `AbstractSchemaManager` (`Doctrine\DBAL\Schema\Table`), which generates
 * correct per-platform DDL from one portable column description -- the
 * same portability this fork's own `Piwigo\Migrations\Version*` classes
 * otherwise get by hand-writing 3 separate MySQL/Postgres/SQLite dialects;
 * requiring that same hand-written-per-platform-DDL discipline of every
 * future plugin author (most starting from 15-year-old MySQL-only legacy
 * code) would be a real regression risk to this fork's own completed
 * Postgres-portability work, not an acceptable cost of the primitive.
 *
 * Namespacing mirrors `ExtensionSession`/`ExtensionCookie` exactly
 * (`ext_<extensionId>_<name>`, a fresh instance per `PluginId`/`ThemeId`) --
 * `tableName()` validates its `$suffix` argument since, unlike a bound
 * query parameter, a table identifier can never be a placeholder: an
 * unvalidated suffix would be exactly `BanIP`'s own real
 * `$_POST['inserip']`-interpolated-into-SQL bug, one level up (identifier
 * instead of value).
 */
final readonly class ExtensionDatabase
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private Connection $connection,
        private PluginId|ThemeId $extensionId,
    ) {}

    public function tableName(string $suffix): string
    {
        if (preg_match(self::NAME_PATTERN, $suffix) !== 1) {
            throw new InvalidArgumentException("Invalid extension table name '{$suffix}' -- must match " . self::NAME_PATTERN . ' (lowercase letters, digits, underscore, starting with a letter).');
        }

        return 'ext_' . $this->extensionId->value . '_' . $suffix;
    }

    /**
     * {@see tableName()}'s own suffix is regex-validated, but `$extensionId`
     * itself isn't -- `PluginId`/`ThemeId` both allow hyphens
     * (`PluginId::PATTERN`). An unquoted hyphenated identifier is a real
     * SQL syntax error on every supported platform, and -- confirmed live
     * against the real vendored source, not assumed -- nothing quotes it
     * automatically: `Connection::insert()` is a bare `'INSERT INTO ' .
     * $table . ...`, `AbstractPlatform::getDropTableSQL()` is a bare
     * `'DROP TABLE ' . $table`, and `Doctrine\DBAL\Schema\Table`'s own DDL
     * generation only treats a name as quoted when the raw string it's
     * given already starts with a quote character
     * (`AbstractAsset::isIdentifierQuoted()`) -- a plain `new
     * Table('ext_my-plugin_stats')` generates unquoted, broken DDL for a
     * hyphenated id. `hasTable()`'s `tablesExist()` is the one exception
     * (schema introspection against normalized metadata, not raw SQL
     * text) -- it takes the plain {@see tableName()} result correctly.
     * Every other method here, {@see createTable()} included, must use
     * this instead.
     */
    private function quotedTableName(string $suffix): string
    {
        return $this->connection->getDatabasePlatform()
            ->quoteSingleIdentifier($this->tableName($suffix));
    }

    public function hasTable(string $suffix): bool
    {
        return $this->connection->createSchemaManager()
            ->tablesExist([$this->tableName($suffix)]);
    }

    /**
     * @param callable(Table): void $configure builds the table's own
     * columns/indexes/primary key -- called with a `Table` already named
     * via {@see tableName()}, portable DDL generated from it by
     * `AbstractSchemaManager` itself, no per-platform branching needed
     * here the way `Piwigo\Migrations\Version*` classes need for core's
     * own schema.
     *
     * Constructed from {@see quotedTableName()}, not the plain logical
     * name -- confirmed live (not assumed) that `Table`'s own DDL
     * generation does NOT auto-quote an unsafe identifier the way this
     * class's own docblock initially assumed: `Doctrine\DBAL\Schema\
     * Identifier` only treats a name as quoted when the raw string it's
     * given already starts with a quote character
     * (`AbstractAsset::isIdentifierQuoted()`), which a pre-quoted
     * `quotedTableName()` result always does.
     */
    public function createTable(string $suffix, callable $configure): void
    {
        $table = new Table($this->quotedTableName($suffix));
        $configure($table);
        $this->connection->createSchemaManager()
            ->createTable($table);
    }

    /**
     * `uninstall()`'s own symmetric counterpart to {@see createTable()} --
     * an extension author's own responsibility to call, same trust level
     * as every other write access this class hands out (see this class's
     * own docblock).
     */
    public function dropTable(string $suffix): void
    {
        // Unlike createTable()'s Table object (whose own name is a
        // quoting-aware Identifier) and hasTable()'s tablesExist()
        // (schema introspection, not raw SQL text), dropTable(string
        // $name) hands $name straight to AbstractPlatform::
        // getDropTableSQL() -- a bare 'DROP TABLE ' . $name concat, per
        // the real vendored source -- so this needs the same pre-quoted
        // name insert()/update()/delete()/select()/count() do.
        $this->connection->createSchemaManager()
            ->dropTable($this->quotedTableName($suffix));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return int the new row's auto-generated id
     */
    public function insert(string $suffix, array $data): int
    {
        $this->connection->insert($this->quotedTableName($suffix), $data);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $suffix, array $data, SqlCondition $criteria): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->update($this->quotedTableName($suffix));
        foreach ($data as $column => $value) {
            $qb->set($column, $qb->createNamedParameter($value));
        }
        $criteria->applyTo($qb);

        return (int) $qb->executeStatement();
    }

    public function delete(string $suffix, SqlCondition $criteria): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->delete($this->quotedTableName($suffix));
        $criteria->applyTo($qb);

        return (int) $qb->executeStatement();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select(string $suffix, ?SqlCondition $criteria = null, ?string $orderByColumn = null, string $orderByDirection = 'ASC', ?int $limit = null): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->quotedTableName($suffix));
        ($criteria ?? SqlCondition::fromRawSql(''))->applyTo($qb);
        if ($orderByColumn !== null) {
            $qb->orderBy($orderByColumn, $orderByDirection);
        }
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array<string, mixed>> */
        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    public function count(string $suffix, ?SqlCondition $criteria = null): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from($this->quotedTableName($suffix));
        ($criteria ?? SqlCondition::fromRawSql(''))->applyTo($qb);

        $result = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
