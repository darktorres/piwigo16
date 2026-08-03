<?php

declare(strict_types=1);

namespace Piwigo\Section\Request;

/**
 * Validated `$_GET['action']` shape for SectionPopulator::populate()'s
 * own "favorites" section branch -- P26/SEC-40 Request DTO.
 */
final readonly class FavoritesActionRequest
{
    private function __construct(
        public bool $removeAllFromFavorites,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        return new self(($get['action'] ?? null) === 'remove_all_from_favorites');
    }
}
