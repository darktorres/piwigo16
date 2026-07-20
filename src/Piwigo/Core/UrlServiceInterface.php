<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Legacy Coupling Retirement Phase 4c: the 18 former free functions
 * bridged through `Piwigo\Url\functions.php` (now deleted; `get_root_url()`
 * alone had ~150 real call sites). `Piwigo\Url\UrlService` is
 * L2bExtendedDomain; real callers span every layer from L1Infrastructure
 * through L4Integration, so they constructor- or method-inject
 * `UrlServiceInterface` instead of depending on the concrete class
 * directly, per deptrac.yaml's ruleset -- same shape as
 * `FilterUpdaterInterface`. Lives in `Piwigo\Core` (L1Infrastructure) so
 * any layer can depend downward on this instead. `UrlService implements`
 * it; bound in `config/container.php`.
 *
 * 3 of UrlService's own public methods (`paramsForDuplication()`,
 * `addWellKnownParamsInUrl()`, `makeSectionInUrl()`) are NOT declared
 * here -- they were never bridged through the free-function file (zero
 * real external callers, confirmed via a direct grep both before and
 * after this phase), so they stay real, concrete-typed internal
 * implementation details, not part of this interface's contract.
 */
interface UrlServiceInterface
{
    /**
     * Returns a prefix for each url link on displayed page and returns an
     * empty string for current path.
     */
    public function getRootUrl(): string;

    /**
     * Returns the absolute url to the root of PWG.
     */
    public function getAbsoluteRootUrl(bool $withScheme = true): string;

    /**
     * @param array<int|string, mixed> $params
     */
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string;

    /**
     * Build an index URL for a specific section.
     *
     * @param array<string, mixed> $params
     */
    public function makeIndexUrl(array $params = []): string;

    /**
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string;

    /**
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string;

    /**
     * Create a picture URL on a specific section for a specific picture.
     *
     * @param array<string, mixed> $params
     */
    public function makePictureUrl(array $params): string;

    /**
     * @param string[] $tokens
     * @param int $nextToken the index in the array of url tokens; in/out
     * @return array<string, mixed>
     */
    public function parseSectionUrl(array $tokens, &$nextToken, RedirectServiceInterface $redirectService): array;

    /**
     * @param string[] $tokens
     * @return array{flat?: true, chronology_field?: string, chronology_style?: string, chronology_view?: string, chronology_date?: list<string>, start?: string, startcat?: string}
     */
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array;

    /**
     * @param int|string $id image id
     * @param string $whatPart one of 'e' (element), 'r' (representative)
     */
    public function getActionUrl($id, $whatPart, bool $download): string;

    /**
     * @param array<string, mixed> $elementInfo containing element information
     * from db; at least 'id', 'path' should be present
     */
    public function getElementUrl(array $elementInfo): mixed;

    /**
     * Indicate to build url with full path.
     */
    public function setMakeFullUrl(): void;

    /**
     * Restore old parameter to build url with full path.
     */
    public function unsetMakeFullUrl(): void;

    /**
     * Embellish the url argument.
     */
    public function embellishUrl(string $url): string;

    /**
     * Returns the 'home page' of this gallery.
     */
    public function getGalleryHomeUrl(): mixed;

    /**
     * Returns $_SERVER['QUERY_STRING'] whithout keys given in parameters.
     *
     * @param string[] $rejects
     * @param bool $escape escape *&* to *&amp;*
     */
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string;

    /**
     * Returns true if the url is absolute (begins with http).
     */
    public function urlIsRemote(string $url): bool;

    /**
     * List favorite image_ids of the current user.
     * @since 13
     *
     * @return array<int|string, mixed>
     */
    public function getUserFavorites(): array;
}
