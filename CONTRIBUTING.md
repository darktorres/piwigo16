# Contributing

## Style

PHP code follows **PSR-12**, enforced by [Laravel Pint](https://laravel.com/docs/pint) with the project rules in `pint.json` (`single_quote`, `ordered_imports`, `no_unused_imports`, `trailing_comma_in_multiline`).

Format the entire tree:

```bash
vendor/bin/pint
```

Format only files changed since the last commit (fast, recommended while iterating):

```bash
vendor/bin/pint --dirty
```

Check without rewriting (this is what CI runs):

```bash
vendor/bin/pint --test
```

CI rejects any push or pull request that fails `vendor/bin/pint --test`.

### Optional pre-commit hook

Drop this in `.git/hooks/pre-commit` and `chmod +x` it to format staged work before each commit:

```bash
#!/bin/sh
exec vendor/bin/pint --dirty
```

### Editor setup

`.editorconfig` at the repo root pins LF line endings, UTF-8, 4-space PHP indentation, and trailing-whitespace trimming. Most editors pick this up automatically; if yours doesn't, install the EditorConfig plugin.

## Dependencies

### Updates

Dependabot opens grouped weekly PRs every Monday at 06:00 UTC for `composer`, `npm`, and `github-actions` (configured in `.github/dependabot.yml`). Minor and patch updates land in a single per-ecosystem PR; major updates arrive as individual PRs requiring manual review.

To bump a single dep manually:

```bash
composer require vendor/package:^X.Y     # add or upgrade prod dep
composer require --dev vendor/package    # dev-only
npm install package@^X.Y                 # add or upgrade prod dep
npm install -D package                   # dev-only
```

Always commit the resulting `composer.lock` / `package-lock.json` change with the dep bump.

### Security advisories

Two layers run automatically:

- **Per-push CI**: `composer audit --abandoned=fail` and `npm audit --omit=dev --audit-level=high` block any push that introduces a known-vulnerable runtime dep or an abandoned composer package.
- **Dependabot alerts**: GitHub opens a security PR as soon as an advisory is published against any dep, regardless of the weekly schedule (toggle in repo Settings → Code security).

When triaging an advisory:

1. Run `composer audit` (or `npm audit`) locally to confirm the call site is actually reachable in our code, not just transitively listed.
2. Prefer the upstream-fixed version. If no fix exists, evaluate whether we use the affected API surface — many advisories cover code paths Piwigo never invokes.
3. To override a finding (rare, requires written rationale in the commit message), suppress it narrowly: `composer audit --ignore=CVE-XXXX-YYYYY` for a one-shot, or pin via `roave/security-advisories` exclusions for the long-term. For npm, document the override in the commit; npm doesn't ship a per-CVE ignore.
4. CVSS ≥ 7 with a known fix → patch within 48 hours. Lower severity or no fix → evaluate at the next dep-update cycle.
