<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Mutable in-memory registry of [[AdminPage]] entries.
 *
 * Populated at admin boot by [[CoreAdminPagesSubscriber]] (built-in
 * pages) and by plugin subscribers (custom pages) — both react to the
 * [[\Piwigo\Event\Admin\AdminPagesRegistering]] event. Once boot
 * finishes, [[\Piwigo\Controller\Admin\AdminController]] uses
 * `find($slug)` to translate a `?page=` URL into the controller class
 * that should handle it.
 *
 * Slug uniqueness is enforced — re-registering the same slug throws,
 * which means a plugin that ships a slug colliding with a core page
 * fails loudly at boot, not silently at request time.
 */
final class AdminPageRegistry
{
    /** @var array<string, AdminPage> */
    private array $pages = [];

    public function register(AdminPage $page): void
    {
        if (isset($this->pages[$page->slug])) {
            throw new \InvalidArgumentException(
                "AdminPage '{$page->slug}' is already registered (existing controller: {$this->pages[$page->slug]->controllerClass}).",
            );
        }
        $this->pages[$page->slug] = $page;
    }

    public function find(string $slug): ?AdminPage
    {
        return $this->pages[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    /** @return array<string, AdminPage> */
    public function all(): array
    {
        return $this->pages;
    }

    /** @return list<AdminPage> */
    public function byGroup(AdminMenuGroup $group): array
    {
        $out = [];
        foreach ($this->pages as $page) {
            if ($page->menuGroup === $group) {
                $out[] = $page;
            }
        }
        return $out;
    }

    /**
     * Number of registered pages. Useful for tests verifying that the
     * core subscriber registered the expected set.
     */
    public function count(): int
    {
        return count($this->pages);
    }
}
