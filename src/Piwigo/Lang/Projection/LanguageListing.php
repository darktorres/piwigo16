<?php

declare(strict_types=1);

namespace Piwigo\Lang\Projection;

/**
 * One row of {@see \Piwigo\Lang\LangRepository::findAllRows()}'s own
 * id/name listing -- {@see \Piwigo\Lang\LangService::getLanguages()}'s
 * real (and only) consumer shape.
 */
final readonly class LanguageListing
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
