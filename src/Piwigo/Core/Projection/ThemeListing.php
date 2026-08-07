<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * One row of {@see \Piwigo\Core\ThemeRepository::findAllIdsAndNames()}'s
 * own id/name listing -- {@see \Piwigo\Core\ThemeCatalog::getPwgThemes()}'s
 * real (and only) consumer shape.
 */
final readonly class ThemeListing
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
