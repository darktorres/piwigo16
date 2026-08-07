<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Template\Template` is L3Presentation, but L2aCoreDomain
 * (`Category\CategoryService`) and L2bExtendedDomain (`Calendar\*`,
 * `Search\SearchFilterRenderer`, `Section\SectionPopulator`) classes have
 * real callers needing to write into the current request's template --
 * deptrac's ruleset forbids all of those from depending upward on L3
 * directly. Lives in `Piwigo\Core` (L1Infrastructure, same direction as
 * `HtmlRenderingInterface`/`MailerInterface`/`ActivityLoggerInterface`/
 * `FilterUpdaterInterface`) so those classes can depend downward on this
 * instead of the concrete class. `Template implements` it; bound in
 * `config/container.php` to a factory resolving
 * `Piwigo\Template\CurrentTemplate::get()` (the current request's template
 * is constructed dynamically per-request with runtime theme/path
 * parameters, so the binding can't be a plain autowired singleton).
 *
 * Only `Template` methods with a real L1/L2a/L2b caller are included here
 * -- the 30+ other `Template` methods (Smarty-plugin registration, filter
 * wiring, theme-conf loading, ...) have no L1/L2a/L2b caller and stay
 * untouched, concrete-class-only.
 */
interface TemplateInterface
{
    /**
     * $value/$tpl_var's hashmap values are genuinely arbitrary by design --
     * matches Smarty's own `assign($tpl_var, $value = null)` signature,
     * the same category as PwgResponseEncoder's generic serialization
     * boundary: any PHP value a caller wants a template to render.
     *
     * @param string|array<string, mixed> $tpl_var can be a var name or a hashmap of variables
     *    (in this case, do not use the _$value_ parameter)
     */
    public function assign(string|array $tpl_var, mixed $value = null): void;

    public function append(string $tpl_var, mixed $value = null, bool $merge = false): void;

    public function assign_var_from_handle(string $varname, string $handle): bool;

    public function set_filename(string $handle, string $filename): bool;

    /**
     * @param array<string, string|null> $filename_array hashmap of
     *   handle=>filename; a null value unsets that handle
     */
    public function set_filenames(array $filename_array): bool;

    public function clear_assign(string $tpl_var): void;

    /**
     * Mirrors assign()'s own arbitrary-value contract -- returns whatever
     * was assigned, unmodified.
     */
    public function get_template_vars(?string $tpl_var = null): mixed;
}
