<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Core\TemplatePageContext;

/**
 * Test-only `TemplatePageContext` wrapping an arbitrary array -- for tests
 * that need to seed a `Template` instance with one or two ad hoc variables
 * via the real `assignContext()` API, without hand-rolling a real
 * `FooPageContext` class or an anonymous class at each call site. Real
 * production code never uses this: every real caller has its own typed
 * `FooPageContext` (see `TemplatePageContext`'s own docblock for why).
 */
final readonly class AdHocPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $vars
     */
    public function __construct(
        private array $vars
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->vars;
    }
}
