<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\PaginationService::createNavigationBar()}'s own
 * result -- previously a bare `array{CURRENT_PAGE?, URL_FIRST?, URL_PREV?,
 * URL_NEXT?, URL_LAST?, pages?, NB_PAGE?}` shape, duplicated verbatim as
 * a `$navbar`/`$thumbNavbar`/`$commentsNavbar`/`$catsNavbar` constructor
 * param docblock across every View/renderer site that carries one.
 *
 * Every real field is independently optional -- `none()` is "single page,
 * no navigation needed", not an error state. Both `navigation_bar.latte`
 * files read these as properties as of P58-A; the `toArray()` that used
 * to flatten it at every View constructor is gone, and with it the
 * array shape that was duplicated as a docblock across ten call sites.
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
     * "No navigation needed", the state `none()` produces and
     * `PaginationService::createNavigationBar()` returns whenever the
     * element count fits on one page.
     *
     * This is what the templates' own `{if !empty($navbar)}` used to ask
     * of the flattened array, and it has to be asked explicitly now:
     * `empty()` on an object is always false, so leaving those guards
     * alone would have rendered a navigation bar on every single-page
     * listing -- and PHPStan does not report it, because `empty()` on an
     * object is legal.
     */
    public function isEmpty(): bool
    {
        return $this->currentPage === null
            && $this->urlFirst === null
            && $this->urlPrev === null
            && $this->urlNext === null
            && $this->urlLast === null
            && $this->pages === []
            && $this->nbPage === null;
    }
}
