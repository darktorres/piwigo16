<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * {@see \Piwigo\Admin\Extensions\ExtensionRepository::findAllThemes()}/
 * {@see \Piwigo\Admin\Extensions\ExtensionRepository::findTheme()}'s own
 * `themes` row shape (`id`/`version`/`name` -- confirmed via
 * `install/piwigo_structure-mysql.sql`; identical column set to
 * `languages`, see {@see LanguageDbRow}, but kept as its own class to
 * match this domain's existing `ThemeScanRow`/`LanguageScanRow` split).
 */
final readonly class ThemeDbRow
{
    public function __construct(
        public string $id,
        public string $version,
        public ?string $name,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            version: is_string($row['version'] ?? null) ? $row['version'] : '0',
            name: is_string($row['name'] ?? null) ? $row['name'] : null,
        );
    }
}
