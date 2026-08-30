<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * One entry of `batch_manager_filter.inc.latte`'s own prefilter
 * `<select>`, built by {@see
 * \Piwigo\Admin\BatchManager\FilterPanelRenderer::render()}.
 *
 * The list is plugin-extensible: `get_batch_manager_prefilters` lets a
 * handler splice in its own rows, and the dispatch only checks
 * `is_array()`, never the `array{ID: string, NAME: string}` shape the
 * built-in entries use. That is why the rows reached the template as
 * `array<mixed>` and every read of `ID` was typed `mixed` (P58-B3).
 *
 * Normalizing to this VO at the boundary keeps the extensibility -- a
 * plugin can still add an entry -- while making the shape the template
 * relies on true by construction rather than by convention. A row that
 * does not supply usable strings is dropped by {@see tryFromArray()}
 * rather than rendered as an option with an empty value, which is what
 * `mixed` reads produced before: an `<option value="">` a user could
 * select, submitting a prefilter no branch handles.
 */
final readonly class BatchManagerPrefilter
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @param array<mixed> $row
     */
    public static function tryFromArray(array $row): ?self
    {
        $id = $row['ID'] ?? null;
        $name = $row['NAME'] ?? null;

        return is_string($id) && $id !== '' && is_string($name)
            ? new self(id: $id, name: $name)
            : null;
    }
}
