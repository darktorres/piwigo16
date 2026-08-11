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
     * Assigns every variable a typed `TemplatePageContext` carries in one
     * call -- the one, sole way any L1/L2a/L2b/L3 caller ever writes into
     * the current request's template (`assign()`/`append()` were removed
     * entirely once the last real caller of either was converted to this;
     * see `tests/Arch/StructuralTest.php` for the guard against a bare
     * `$template->smarty->assign()`/`append()` reach-around).
     */
    public function assignContext(TemplatePageContext $context): void;

    public function assignVarFromHandle(string $varname, string $handle): bool;

    public function setFilename(string $handle, string $filename): bool;

    /**
     * @param array<string, string|null> $filename_array hashmap of
     *   handle=>filename; a null value unsets that handle
     */
    public function setFilenames(array $filename_array): bool;

    public function clearAssign(string $tpl_var): void;

    /**
     * Mirrors assign()'s own arbitrary-value contract -- returns whatever
     * was assigned, unmodified.
     */
    // Same blind spot as CalendarBase::generateCategoryContent() -- heavily used, just not traced through the interface.
    // @phpstan-ignore shipmonk.deadMethod
    public function getTemplateVars(?string $tpl_var = null): mixed;
}
