<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One plugin tile on `admin.php?page=plugins&tab=new`, built by
 * {@see \Piwigo\Admin\PluginsNewPageRenderer::render()} from a
 * {@see \Piwigo\Extension\PemCatalog} manifest entry.
 *
 * Every field arrives from the catalog's JSON as `mixed` and is narrowed at
 * construction: a manifest is a mirror of an external payload, so a missing
 * or wrong-typed field is a data condition, not a programming error, and the
 * page renders the row regardless.
 *
 * `$revisionDate` is the raw `Y-m-d H:i:s` the manifest carries.
 * `plugins_new.latte` runs it through the `format_date` and `time_since`
 * filters, which are the same `DateHelper` calls the producer used to make
 * eagerly. `$revisionSort` is the separate, digits-only `strtotime()` value
 * `plugins/new.ts` sorts on through `data-revision`; it is not a rendered
 * date and does not go through a filter.
 *
 * `$id` doubles as the "post date" sort key: `data-date` carries it, and the
 * catalog issues extension ids in registration order, so ordering by id is
 * ordering by when an extension first appeared. That is deliberate, not the
 * copy-paste it reads as.
 */
final readonly class CatalogPluginRow
{
    /**
     * @param array<int|string, string> $tags tag id => label, rendered as a
     *   comma-joined title and as one link per entry
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public string $description,
        public string $version,
        public string $revisionSort,
        public string $revisionDate,
        public string $author,
        public int $downloads,
        public string $installUrl,
        public int $certification,
        public ?float $rating,
        public int $nbRatings,
        public string $screenshot,
        public array $tags,
    ) {}
}
