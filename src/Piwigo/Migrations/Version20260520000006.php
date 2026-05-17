<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * Replace the serialized `piwigo_conf.filters_views` row with a
 * dedicated `piwigo_search_filter_view` table.
 *
 * The legacy conf row stored the admin's whole filter-view config map
 * as one PHP-serialized blob. The new table makes each filter view a
 * discrete row: one row per name with the per-name config JSON
 * inline. Same `default_filters_views` SCHEMA fallback seeded on fresh
 * installs that had no legacy row.
 *
 * isTransactional()=false because MyISAM CREATE TABLE implicit-commits
 * Doctrine's transaction wrapper (matches Version20260520000005).
 */
final class Version20260520000006 extends AbstractMigration
{
    /**
     * Defaults seeded into the new table when no legacy conf row is
     * present. Kept inline here so the migration is self-contained and
     * doesn't drift if Config::defaultFiltersViews() changes later.
     *
     * @var array<string, mixed>
     */
    private const array DEFAULT_FILTER_VIEWS = [
        'words'             => ['access' => 'everybody', 'default' => true],
        'tags'              => ['access' => 'everybody', 'default' => false],
        'post_date'         => ['access' => 'everybody', 'default' => false],
        'creation_date'     => ['access' => 'everybody', 'default' => true],
        'album'             => ['access' => 'everybody', 'default' => true],
        'author'            => ['access' => 'everybody', 'default' => false],
        'added_by'          => ['access' => 'everybody', 'default' => false],
        'file_type'         => ['access' => 'everybody', 'default' => false],
        'ratio'             => ['access' => 'everybody', 'default' => false],
        'rating'            => ['access' => 'everybody', 'default' => false],
        'file_size'         => ['access' => 'everybody', 'default' => false],
        'height'            => ['access' => 'everybody', 'default' => false],
        'width'             => ['access' => 'everybody', 'default' => false],
        'expert'            => ['access' => 'everybody', 'default' => false],
        'last_filters_conf' => true,
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Create piwigo_search_filter_view table and migrate the legacy filters_views conf row';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'search_filter_view';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            $this->addSql(
                'CREATE TABLE `' . $tableName . '` ('
                . '`name` VARCHAR(64) CHARACTER SET ascii NOT NULL, '
                . '`config_json` TEXT NOT NULL, '
                . '`created_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`name`)'
                . ') ENGINE=MyISAM'
            );
        }
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'search_filter_view';
        $confTable = $prefix . 'config';

        $legacyValue = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['filters_views']
        )->fetchOne();

        $seed = self::DEFAULT_FILTER_VIEWS;
        if (is_string($legacyValue) && $legacyValue !== '') {
            $decoded = StringUtil::safeUnserialize($legacyValue);
            if ($decoded !== []) {
                $seed = $decoded;
            }
        }

        foreach ($seed as $name => $config) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $this->connection->executeStatement(
                'INSERT IGNORE INTO `' . $tableName
                . '` (name, config_json, created_at) VALUES (?, ?, NOW())',
                [
                    $name,
                    json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]
            );
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param IN (?, ?)',
            ['filters_views', 'default_filters_views']
        );
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'search_filter_view';
        $confTable = $prefix . 'config';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            return;
        }

        $rows = $this->connection->executeQuery(
            'SELECT name, config_json FROM `' . $tableName . '`'
        )->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : null;
            $raw  = is_string($row['config_json'] ?? null) ? $row['config_json'] : null;
            if ($name === null || $raw === null) {
                continue;
            }
            $decoded   = json_decode($raw, associative: true);
            $map[$name] = is_array($decoded) || is_bool($decoded) ? $decoded : null;
        }

        if ($map !== []) {
            $blob = serialize($map);
            $this->connection->executeStatement(
                'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['filters_views', $blob]
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'search_filter_view';
        $sm        = $this->connection->createSchemaManager();

        if ($sm->tablesExist([$tableName])) {
            $this->addSql('DROP TABLE `' . $tableName . '`');
        }
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
