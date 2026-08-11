<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Url\UrlService` is L2bExtendedDomain; real callers span every
 * layer from L1Infrastructure through L4Integration, so they constructor-
 * or method-inject `UrlServiceInterface` instead of depending on the
 * concrete class directly, per deptrac.yaml's ruleset. Lives in
 * `Piwigo\Core` (L1Infrastructure) so any layer can depend downward on
 * this instead. `UrlService implements` it; bound in
 * `config/container.php`.
 *
 * `UrlService`'s own `paramsForDuplication()`, `addWellKnownParamsInUrl()`,
 * and `makeSectionInUrl()` methods are not declared here: they have no
 * external callers, so they stay real, concrete-typed internal
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
     * $params' values are genuinely arbitrary by design -- matches PHP's own
     * http_build_query(array|object $data) contract; every real value is
     * scalar-checked at its own use site (is_scalar()/is_array()/
     * is_string()) rather than assumed.
     *
     * @param array<int|string, mixed> $params
     */
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string;

    /**
     * Build an index URL for a specific section. Same arbitrary-value
     * rationale as addUrlParams() -- $params spans gallery-navigation keys
     * (section, category, tags, chronology_*, start, flat, root_path, ...),
     * each independently scalar/array-checked where read.
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
     * Same arbitrary-value rationale as makeIndexUrl().
     *
     * @param array<string, mixed> $params
     */
    public function makePictureUrl(array $params): string;

    /**
     * @param string[] $tokens
     * @param int $nextToken the index in the array of url tokens; in/out
     * @return array<string, mixed>
     */
    public function parseSectionUrl(array $tokens, int &$nextToken, RedirectServiceInterface $redirectService): array;

    /**
     * @param string[] $tokens
     * @return array{flat?: true, chronology_field?: string, chronology_style?: string, chronology_view?: string, chronology_date?: list<string>, start?: string, startcat?: string}
     */
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array;

    /**
     * @param int|string $id image id
     * @param string $whatPart one of 'e' (element), 'r' (representative)
     */
    public function getActionUrl(int|string $id, string $whatPart, bool $download): string;

    /**
     * @param array<string, mixed> $elementInfo containing element information
     * from db; at least 'id', 'path' should be present
     */
    public function getElementUrl(array $elementInfo): string;

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
    public function getGalleryHomeUrl(): string;

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
     * List favorite image_ids of the current user. Every real caller only
     * checks key existence -- the value is always the query's own literal
     * `1`.
     *
     * @return array<int, int>
     */
    public function getUserFavorites(): array;
}
