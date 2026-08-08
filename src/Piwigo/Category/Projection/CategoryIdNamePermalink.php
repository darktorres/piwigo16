<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * Shared `id`/`name`/`permalink` row shape for
 * {@see \Piwigo\Category\CategoryRepository::findNamesByIds()},
 * {@see \Piwigo\Category\CategoryRepository::findAllIdNamePermalink()},
 * and {@see \Piwigo\Category\CategoryRepository::findIdNamePermalinkById()}
 * -- all 3 select the same 3 columns.
 *
 * `toArray()` preserves the exact original snake_case shape: every real
 * consumer of these 3 repository methods reaches this DTO only through
 * {@see \Piwigo\Category\CategoryService}'s own pass-through methods,
 * which keep their existing plain-array return contracts (WS responses
 * and a `CategoryService::getCategoryInfo()` splice site among them) --
 * `toArray()` is what lets the service layer stay unchanged.
 */
final readonly class CategoryIdNamePermalink
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
    ) {}

    /**
     * @return array{id: int, name: string, permalink: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
        ];
    }
}
