<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Persistence for per-size derivative parameters. One row per defined
 * size (square / thumb / medium / large / 2small / xsmall / xlarge /
 * 2xlarge / 3xlarge / 4xlarge / Custom).
 *
 * Replaces the legacy `derivatives` + `disabled_derivatives` conf rows
 * (which packed the whole DerivativeParams object graph behind PHP
 * serialize). The dedicated table makes each size a row admins can
 * edit / disable / enable independently, and lets the bootstrap path
 * read rows without booting Kernel just to call ConfigService.
 */
final readonly class DerivativeSizeRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * Load all sizes in a single query, partitioned by enabled status.
     *
     * @return array{enabled: array<string, DerivativeParams>, disabled: array<string, DerivativeParams>}
     */
    public function loadAll(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT name, enabled, max_width, max_height, max_crop, '
            . 'min_width, min_height, sharpen, last_mod_time '
            . 'FROM ' . Tables::derivativeSize()
        )->fetchAllAssociative();

        $enabled  = [];
        $disabled = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $params = $this->rowToParams($name, $row);
            if (!empty($row['enabled'])) {
                $enabled[$name] = $params;
            } else {
                $disabled[$name] = $params;
            }
        }
        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    public function hasAny(): bool
    {
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::derivativeSize()
        )->fetchOne();
        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * Replace the entire size table atomically: TRUNCATE then INSERT
     * one row per enabled size, one row per disabled size. Matches the
     * legacy "whole-blob overwrite" semantics of ImageStdParams::save().
     *
     * @param array<string, DerivativeParams> $enabled
     * @param array<string, DerivativeParams> $disabled
     */
    public function replaceAll(array $enabled, array $disabled): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());
        foreach ($enabled as $name => $params) {
            $this->insertRow($name, $params, true);
        }
        foreach ($disabled as $name => $params) {
            $this->insertRow($name, $params, false);
        }
    }

    private function insertRow(string $name, DerivativeParams $params, bool $enabled): void
    {
        $sizing = $params->sizing;
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::derivativeSize()
            . ' (name, enabled, max_width, max_height, max_crop, min_width, min_height, sharpen, last_mod_time)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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

    /** @param array<string, mixed> $row */
    private function rowToParams(string $name, array $row): DerivativeParams
    {
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
        return $params;
    }
}
