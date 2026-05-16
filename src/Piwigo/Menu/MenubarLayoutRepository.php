<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Config\ConfigService;
use Piwigo\Core\StringUtil;

/**
 * Owns the persisted `blk_menubar` row: each block ID mapped to a signed
 * position (positive = visible, ordering ascending; negative = hidden).
 *
 * Hides the conf-table magic string from every caller. Stored on disk as a
 * PHP-serialized `array<string, int>` for backwards compatibility with the
 * existing row format — the encoding is not exposed.
 */
final readonly class MenubarLayoutRepository
{
    private const string CONF_KEY = 'blk_menubar';

    public function __construct(private ConfigService $configService)
    {
    }

    /** @return array<string, int> */
    public function load(): array
    {
        $raw = $this->configService->confGetParam(self::CONF_KEY, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = StringUtil::safeUnserialize($raw);
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @param array<string, int> $blocks */
    public function save(array $blocks): void
    {
        $this->configService->confUpdateParam(self::CONF_KEY, serialize($blocks));
    }
}
