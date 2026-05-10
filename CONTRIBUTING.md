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

## Config schema

`Piwigo\Config\Config::SCHEMA` is the source-of-truth registry for every config
key Piwigo recognises (287 entries; full list in
[`docs/CONFIG-REFERENCE.md`](docs/CONFIG-REFERENCE.md)). The typed accessors
below the `<<<CONFIG-ACCESSORS-BEGIN>>>` sentinel are **generated** by
`tools/build-config-accessors.php` — never hand-edit anything between the
BEGIN/END sentinels.

### Adding a new key

1. Add the entry to `Config::SCHEMA` (alphabetically, snake_case key, type +
   default + camelCase method name).
2. Run the generator:

   ```bash
   php tools/build-config-accessors.php
   php tools/build-config-reference.php
   ```

3. CI's `SchemaIntegrityTest` enforces SCHEMA ↔ generated-accessors sync;
   the build fails if you forget to re-run the generator.

### Custom (hand-written) accessors

If access logic doesn't fit `getString` / `getInt` / `getBool` (e.g., the value
is a structured array, needs validation, or has cluster-specific defaults),
mark the SCHEMA entry with `'custom' => true` and write the accessor by hand
**below** the `<<<CONFIG-ACCESSORS-END>>>` sentinel. The generator skips custom
entries.

### Dynamic / parametric keys

Keys computed at runtime (per-block menu config like `blk_*`, semaphores like
`<token>_running`, derived caches like `flip_picture_ext`) cannot be expressed
in SCHEMA. Use `Config::raw(string $key, mixed $default = null)` for those —
it bypasses SCHEMA validation by design. Static keys MUST go through a typed
accessor; the private getters throw `UnknownConfigKeyException` if called with
an unregistered key.

### Env-var overrides

A small curated subset of keys can be overridden at runtime via `.env`.
The mapping lives in `ConfigLoader::ENV_MAPPING`; extend it (and validate
the target key is in SCHEMA) when more keys need 12-factor injection.

### Per-plugin Config classes

Bundled plugins each ship their own typed Config class under
`Piwigo\Plugins\<PluginName>\Config` (autoloaded from `src/Piwigo/Plugins/`).
The pattern mirrors Piwigo's main Config: a `SCHEMA` constant for the
plugin's owned keys, plus typed accessors. Reads narrow `$GLOBALS['conf']`
to an array via a private `confArray()` helper; writes go through
`ConfigStorage::persist()` for the DB and `Piwigo\Config\Config::override()`
for the in-memory bridge.

Existing examples:

- `Piwigo\Plugins\LocalFilesEditor\Config` — single string-list key
- `Piwigo\Plugins\NbcThemeChanger\Config` — semicolon-encoded list
- `Piwigo\Plugins\PiwigoOpenstreetmap\Config` — single deeply-nested
  array stored serialized; section-level accessors
- `Piwigo\Plugins\PiwigoVideojs\Config` — five keys (player config,
  sync probes, custom CSS, mediainfo/exiftool path overrides)

The PHPStan `ConfigKeyExistsRule` skips files under `src/Piwigo/Plugins/`
since plugin Config classes legitimately call
`Piwigo\Config\Config::override()` with their plugin-owned keys (which
aren't in Piwigo's main SCHEMA).
