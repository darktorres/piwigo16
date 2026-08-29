<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed replacement for the ambient
 * `PLUGINS_BATCH_MANAGER_UNIT_ELEMENT_SUBTEMPLATE` template variable,
 * which plugins reached by calling `$template->append()` and
 * `batch_manager_unit.latte` iterated straight out of the template bag --
 * so it arrived as `mixed` and its `{foreach}` had nothing to check
 * against (P58-A).
 *
 * Each entry is a template path the per-element form includes. Two
 * plugins in the reference set use it (`piwigo-openstreetmap`,
 * `Copyrights`), both appending one path; nothing in-tree registers a
 * handler.
 *
 * Those two supply Smarty `.tpl` files, which this engine cannot render
 * either way -- the paths a v17 plugin registers here are `.latte`. That
 * is the same deliberate break as every other PEM contract in this
 * release, not a regression introduced by typing the hook.
 */
final class GetBatchManagerUnitElementSubtemplates
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
    ) {}
}
