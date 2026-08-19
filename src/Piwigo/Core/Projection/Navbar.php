<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\PaginationService::createNavigationBar()}'s own
 * result -- previously a bare `array{CURRENT_PAGE?, URL_FIRST?, URL_PREV?,
 * URL_NEXT?, URL_LAST?, pages?, NB_PAGE?}` shape, duplicated verbatim as
 * a `$navbar`/`$thumbNavbar`/`$catsNavbar` constructor param docblock
 * across every `*PageContext`/renderer site that carries one.
 *
 * Every real field is independently optional, matching the .latte
 * files' own per-key `isset()` checks -- `none()` is "single page, no
 * navigation needed", not an error state. `toArray()` is the literal
 * template-assign boundary: every real caller hands `createNavigationBar()`'s
 * result straight to a `*PageContext` constructor and never reads a key
 * in PHP.
 */
final readonly class Navbar
{
    /**
     * @param array<int, string> $pages
     */
    public function __construct(
        public ?float $currentPage = null,
        public ?string $urlFirst = null,
        public ?string $urlPrev = null,
        public ?string $urlNext = null,
        public ?string $urlLast = null,
        public array $pages = [],
        public ?int $nbPage = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * {@see \Piwigo\Core\PaginationService::createNavigationBar()}'s own
     * internal array-building logic stays untouched (and its dedicated
     * mutation-tested coverage with it) -- this is the one conversion
     * point at the very end, from that raw legacy shape to this VO.
     *
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $row
     */
    public static function fromLegacyArray(array $row): self
    {
        return new self(
            currentPage: $row['CURRENT_PAGE'] ?? null,
            urlFirst: $row['URL_FIRST'] ?? null,
            urlPrev: $row['URL_PREV'] ?? null,
            urlNext: $row['URL_NEXT'] ?? null,
            urlLast: $row['URL_LAST'] ?? null,
            pages: $row['pages'] ?? [],
            nbPage: $row['NB_PAGE'] ?? null,
        );
    }

    /**
     * @return array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}
     */
    public function toArray(): array
    {
        $result = [];
        if ($this->currentPage !== null) {
            $result['CURRENT_PAGE'] = $this->currentPage;
        }

        if ($this->urlFirst !== null) {
            $result['URL_FIRST'] = $this->urlFirst;
        }

        if ($this->urlPrev !== null) {
            $result['URL_PREV'] = $this->urlPrev;
        }

        if ($this->urlNext !== null) {
            $result['URL_NEXT'] = $this->urlNext;
        }

        if ($this->urlLast !== null) {
            $result['URL_LAST'] = $this->urlLast;
        }

        if ($this->pages !== []) {
            $result['pages'] = $this->pages;
        }

        if ($this->nbPage !== null) {
            $result['NB_PAGE'] = $this->nbPage;
        }

        return $result;
    }
}
