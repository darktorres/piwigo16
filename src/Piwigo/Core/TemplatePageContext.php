<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Marker interface for a page/renderer's typed Smarty variable set --
 * every real property a `final readonly class FooPageContext` declares
 * is one former `Template::assign('SOME_VAR', $value)` call, now
 * constructed and typed at the call site instead of assigned loose and
 * untyped one variable at a time. `toArray()` is the one place a context
 * class unwraps down to the flat, string-keyed shape Smarty itself
 * requires -- the same boundary-only unwrap convention every VO/DTO in
 * this codebase already uses at its own real serialization boundary.
 * Consumed by `TemplateInterface::assignContext()`.
 */
interface TemplatePageContext
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
