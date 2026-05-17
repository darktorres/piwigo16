<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;

/**
 * Replace the serialized `derivatives` + `disabled_derivatives` conf
 * rows with two dedicated tables:
 *
 *   - `piwigo_derivative_size`     one row per size (enabled or disabled).
 *   - `piwigo_derivative_settings` singleton row for global quality,
 *                                  watermark JSON, custom-key recency.
 *
 * Reads the legacy serialize() blobs, fans the per-size DerivativeParams
 * objects out into rows, writes the WatermarkParams/quality/custom map
 * into the singleton row, then deletes both legacy conf rows.
 *
 * isTransactional()=false — MyISAM DDL implicit-commits Doctrine's
 * transaction wrapper.
 */
final class Version20260520000007 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create piwigo_derivative_size + piwigo_derivative_settings tables and migrate the legacy derivatives / disabled_derivatives conf rows';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();
        $sm     = $this->connection->createSchemaManager();

        $sizeTable = $prefix . 'derivative_size';
        if (!$sm->tablesExist([$sizeTable])) {
            $this->addSql(
                'CREATE TABLE `' . $sizeTable . '` ('
                . '`name` VARCHAR(32) CHARACTER SET ascii NOT NULL, '
                . '`enabled` TINYINT(1) NOT NULL DEFAULT 1, '
                . '`max_width` INT NOT NULL DEFAULT 0, '
                . '`max_height` INT NOT NULL DEFAULT 0, '
                . '`max_crop` DECIMAL(5,4) NOT NULL DEFAULT 0, '
                . '`min_width` INT NULL DEFAULT NULL, '
                . '`min_height` INT NULL DEFAULT NULL, '
                . '`sharpen` DECIMAL(5,4) NOT NULL DEFAULT 0, '
                . '`last_mod_time` INT NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`name`)'
                . ') ENGINE=MyISAM'
            );
        }

        $settingsTable = $prefix . 'derivative_settings';
        if (!$sm->tablesExist([$settingsTable])) {
            $this->addSql(
                'CREATE TABLE `' . $settingsTable . '` ('
                . '`id` TINYINT NOT NULL, '
                . '`default_quality` INT NOT NULL DEFAULT 95, '
                . '`watermark_json` TEXT NOT NULL, '
                . '`custom_json` TEXT NOT NULL, '
                . 'PRIMARY KEY (`id`)'
                . ') ENGINE=MyISAM'
            );
        }
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix        = $this->resolvePrefix();
        $sizeTable     = $prefix . 'derivative_size';
        $settingsTable = $prefix . 'derivative_settings';
        $confTable     = $prefix . 'config';

        $derivativesRaw  = $this->fetchConfValue($confTable, 'derivatives');
        $disabledRaw     = $this->fetchConfValue($confTable, 'disabled_derivatives');
        $derivativesData = $derivativesRaw !== null ? StringUtil::safeUnserialize($derivativesRaw) : [];
        $disabledData    = $disabledRaw !== null ? StringUtil::safeUnserialize($disabledRaw) : [];

        $typeMap = is_array($derivativesData['d'] ?? null) ? $derivativesData['d'] : [];
        foreach ($typeMap as $name => $params) {
            if (is_string($name) && $params instanceof DerivativeParams) {
                $this->insertSize($sizeTable, $name, $params, true);
            }
        }
        foreach ($disabledData as $name => $params) {
            if (is_string($name) && $params instanceof DerivativeParams) {
                $this->insertSize($sizeTable, $name, $params, false);
            }
        }

        $watermark = $derivativesData['w'] ?? null;
        if (!$watermark instanceof WatermarkParams) {
            $watermark = new WatermarkParams();
        }
        $quality = is_int($derivativesData['q'] ?? null) ? $derivativesData['q'] : 95;
        $custom  = is_array($derivativesData['c'] ?? null) ? $derivativesData['c'] : [];
        $customClean = [];
        foreach ($custom as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $customClean[$key] = (int) $value;
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO `' . $settingsTable
            . '` (id, default_quality, watermark_json, custom_json) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE default_quality = VALUES(default_quality), '
            . 'watermark_json = VALUES(watermark_json), custom_json = VALUES(custom_json)',
            [
                1,
                $quality,
                json_encode($watermark->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($customClean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]
        );

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param IN (?, ?)',
            ['derivatives', 'disabled_derivatives']
        );
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix        = $this->resolvePrefix();
        $sizeTable     = $prefix . 'derivative_size';
        $settingsTable = $prefix . 'derivative_settings';
        $confTable     = $prefix . 'config';
        $sm            = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$sizeTable]) || !$sm->tablesExist([$settingsTable])) {
            return;
        }

        $rows = $this->connection->executeQuery(
            'SELECT name, enabled, max_width, max_height, max_crop, min_width, min_height, sharpen, last_mod_time '
            . 'FROM `' . $sizeTable . '`'
        )->fetchAllAssociative();

        $enabled  = [];
        $disabled = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $minW = $row['min_width'] ?? null;
            $minH = $row['min_height'] ?? null;
            $minSize = ($minW !== null && $minH !== null)
                ? [is_numeric($minW) ? (int) $minW : 0, is_numeric($minH) ? (int) $minH : 0]
                : null;
            $sizing = new SizingParams(
                [
                    is_numeric($row['max_width'] ?? null) ? (int) $row['max_width'] : 0,
                    is_numeric($row['max_height'] ?? null) ? (int) $row['max_height'] : 0,
                ],
                is_numeric($row['max_crop'] ?? null) ? (float) $row['max_crop'] : 0.0,
                $minSize
            );
            $params = new DerivativeParams($sizing);
            $params->type          = $name;
            $params->sharpen       = is_numeric($row['sharpen'] ?? null) ? (float) $row['sharpen'] : 0.0;
            $params->last_mod_time = is_numeric($row['last_mod_time'] ?? null) ? (int) $row['last_mod_time'] : 0;
            if (!empty($row['enabled'])) {
                $enabled[$name] = $params;
            } else {
                $disabled[$name] = $params;
            }
        }

        $settingsRow = $this->connection->executeQuery(
            'SELECT default_quality, watermark_json, custom_json FROM `' . $settingsTable . '` WHERE id = 1'
        )->fetchAssociative();

        $quality = 95;
        $watermark = new WatermarkParams();
        $custom = [];
        if ($settingsRow !== false) {
            if (is_numeric($settingsRow['default_quality'] ?? null)) {
                $quality = (int) $settingsRow['default_quality'];
            }
            if (is_string($settingsRow['watermark_json'] ?? null)) {
                $wmDecoded = json_decode($settingsRow['watermark_json'], associative: true);
                if (is_array($wmDecoded)) {
                    /** @var array<string, mixed> $wmDecoded */
                    $watermark = WatermarkParams::fromArray($wmDecoded);
                }
            }
            if (is_string($settingsRow['custom_json'] ?? null)) {
                $customDecoded = json_decode($settingsRow['custom_json'], associative: true);
                if (is_array($customDecoded)) {
                    $custom = $customDecoded;
                }
            }
        }

        $derivativesBlob = serialize([
            'd' => $enabled,
            'q' => $quality,
            'w' => $watermark,
            'c' => $custom,
        ]);
        $this->connection->executeStatement(
            'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['derivatives', $derivativesBlob]
        );

        if ($disabled !== []) {
            $this->connection->executeStatement(
                'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['disabled_derivatives', serialize($disabled)]
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix        = $this->resolvePrefix();
        $sizeTable     = $prefix . 'derivative_size';
        $settingsTable = $prefix . 'derivative_settings';
        $sm            = $this->connection->createSchemaManager();

        if ($sm->tablesExist([$sizeTable])) {
            $this->addSql('DROP TABLE `' . $sizeTable . '`');
        }
        if ($sm->tablesExist([$settingsTable])) {
            $this->addSql('DROP TABLE `' . $settingsTable . '`');
        }
    }

    private function insertSize(string $sizeTable, string $name, DerivativeParams $params, bool $enabled): void
    {
        $sizing = $params->sizing;
        $this->connection->executeStatement(
            'INSERT IGNORE INTO `' . $sizeTable
            . '` (name, enabled, max_width, max_height, max_crop, min_width, min_height, sharpen, last_mod_time) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $enabled ? 1 : 0,
                $sizing->ideal_size[0],
                $sizing->ideal_size[1],
                $sizing->max_crop,
                $sizing->min_size[0] ?? null,
                $sizing->min_size[1] ?? null,
                $params->sharpen,
                $params->last_mod_time,
            ]
        );
    }

    private function fetchConfValue(string $confTable, string $param): ?string
    {
        $value = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            [$param]
        )->fetchOne();
        return is_string($value) ? $value : null;
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
