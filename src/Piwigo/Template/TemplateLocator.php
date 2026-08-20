<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Owns the per-instance theme directory chain and resolves a bare
 * `.latte` filename (or `templateExists()`'s own existence check)
 * against it, in resolution order -- `Template` constructs one of these
 * internally per instance (same `new PageAssets(...)` shape already
 * used in that constructor), not a shared/injected
 * collaborator, since the chain itself is genuinely per-`Template`-instance
 * state (P41, docs/PLAN.md's `TemplateLocator`/`ThemeChain` extraction).
 */
final class TemplateLocator
{
    /**
     * @var list<string>
     */
    private array $dirs = [];

    public function addDir(string $dir): void
    {
        $this->dirs[] = $dir;
    }

    public function firstDir(): string
    {
        return $this->dirs[0] ?? '';
    }

    /**
     * Resolves a bare `.latte` filename to a real, absolute filesystem
     * path: the first hit walking the theme directory chain in order,
     * falling back to `$projectRoot`-relative resolution. Returns `null`
     * on a genuine miss -- `Template::resolveLatteTemplatePath()` is the
     * one real caller, converting that into its own fatal-error path.
     */
    public function resolve(string $file, string $projectRoot): ?string
    {
        // Already a real, absolute filesystem path -- FileCombiner's own
        // "template=true" combinable rendering (CSS/JS files rendered
        // through the template engine before being combined) resolves
        // $combinable->path to a real path via realpath() itself, before
        // ever reaching here; walking $this->dirs against an
        // already-absolute path below would double-prefix it into a
        // nonexistent candidate.
        if (str_starts_with($file, '/') && file_exists($file)) {
            return $file;
        }

        foreach ($this->dirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $file;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Real, live case: search_filters.inc.latte's own {include
        // $ROOT_PATH . 'themes/admin/default/template/include/album_selector.inc.latte'}
        // -- a full, project-root-relative path reaching across into a
        // different theme entirely, not resolvable against this instance's
        // own (single-theme) directory chain. Smarty resolves this the
        // same way: a file= path not found via any registered
        // template_dir falls back to being treated as relative to the
        // current working directory, which for every real entry point in
        // this app is $projectRoot.
        $rootCandidate = rtrim($projectRoot, '/') . '/' . $file;
        if (file_exists($rootCandidate)) {
            return $rootCandidate;
        }

        return null;
    }

    /**
     * Whether `$file` resolves against the theme directory chain --
     * `Template::templateExists()`'s own direct replacement for the
     * legacy `$tpl->smarty->templateExists()` check used by mail
     * rendering (`MailService`'s 3 direct call sites).
     */
    public function exists(string $file): bool
    {
        if (file_exists($file)) {
            return true;
        }

        return array_any($this->dirs, fn (string $dir): bool => file_exists(rtrim($dir, '/') . '/' . $file));
    }
}
