<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One language tile on `admin.php?page=languages&tab=new`, built by
 * {@see \Piwigo\Admin\LanguagesNewPageRenderer::render()} from a
 * {@see \Piwigo\Extension\PemCatalog} manifest entry.
 *
 * Same provenance as {@see CatalogPluginRow}: every field arrives from
 * the catalog's JSON as `mixed` and is narrowed at construction,
 * because a manifest mirrors an external payload and a missing or
 * wrong-typed field is a data condition rather than a programming
 * error.
 *
 * `$date` is the `Y-m-d` half of the revision timestamp, already split
 * from it -- unlike the plugin catalog, this page renders the date and
 * never sorts on it, so there is no second, digits-only form.
 */
final readonly class CatalogLanguageRow
{
    public function __construct(
        public string $name,
        public string $description,
        public string $url,
        public string $version,
        public string $versionDescription,
        public string $date,
        public string $author,
        public string $installUrl,
        public string $downloadUrl,
    ) {}
}
