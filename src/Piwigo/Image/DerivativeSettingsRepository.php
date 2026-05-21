<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Singleton-row repository for derivative-wide settings: JPEG quality,
 * global watermark config, and the auto-generated "custom" size key
 * recency map.
 *
 * The legacy storage packed these into the same `derivatives` conf
 * row that also held the per-size params object graph; B8 splits them
 * so each storage shape has a sensible home.
 *
 * The table always holds at most one row with `id = 1`. Reads return
 * defaults when the row is absent; saves UPSERT.
 */
final readonly class DerivativeSettingsRepository
{
    private const int SINGLETON_ID = 1;

    public function __construct(private Connection $conn)
    {
    }

    public function load(): DerivativeSettings
    {
        $row = $this->conn->executeQuery(
            'SELECT default_quality, watermark_json, custom_json FROM ' . Tables::derivativeSettings()
            . ' WHERE id = ?',
            [self::SINGLETON_ID]
        )->fetchAssociative();

        $quality   = 95;
        $watermark = new WatermarkParams();
        $custom    = [];

        if ($row !== false) {
            if (is_numeric($row['default_quality'] ?? null)) {
                $quality = (int) $row['default_quality'];
            }
            $wmRaw = is_string($row['watermark_json'] ?? null) ? json_decode($row['watermark_json'], associative: true) : null;
            if (is_array($wmRaw)) {
                /** @var array<string, mixed> $wmRaw */
                $watermark = WatermarkParams::fromArray($wmRaw);
            }
            $customRaw = is_string($row['custom_json'] ?? null) ? json_decode($row['custom_json'], associative: true) : null;
            if (is_array($customRaw)) {
                foreach ($customRaw as $key => $value) {
                    if (is_string($key) && is_numeric($value)) {
                        $custom[$key] = (int) $value;
                    }
                }
            }
        }

        return new DerivativeSettings($quality, $watermark, $custom);
    }

    /**
     * Upsert the singleton row.
     *
     * @param array<string, int> $custom
     */
    public function save(int $quality, WatermarkParams $watermark, array $custom): void
    {
        $watermarkJson = json_encode($watermark->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $customJson    = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::derivativeSettings()
            . ' (id, default_quality, watermark_json, custom_json) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE default_quality = VALUES(default_quality), '
            . 'watermark_json = VALUES(watermark_json), custom_json = VALUES(custom_json)',
            [self::SINGLETON_ID, $quality, $watermarkJson, $customJson]
        );
    }
}
