# P58 tooling — the phpstan-latte CAMPAIGN-PENDING campaigns

`phpstan.neon`'s CAMPAIGN-PENDING block suppresses every finding P58 exists
to fix, so `composer analyse:phpstan` reports zero and says nothing about
progress. These four scripts are how the campaign is measured and executed.
They live in the repo rather than in a scratch directory because the campaign
re-measures after every step; without them no number in the plan is
checkable.

## The pipeline

```sh
php bin/piwigo phpstan-latte:compile              # templates -> _analysis/
php tools/p58/census.php --json=census.json       # findings, both campaigns
php tools/p58/trace.php census.json > trace.json  # finding -> View property
php tools/p58/assign.php trace.json               # property -> fix technique
```

**Re-compile first, always.** A stale compile shows up as shipmonk reporting
every property of a newly-typed VO as "never read", and as findings that no
longer exist.

### `census.php`

Strips the CAMPAIGN-PENDING block into a scratch config _beside_
`phpstan.neon` — its `paths`/`excludePaths` are relative to the config file's
own directory, so a config in `/tmp` silently analyses nothing — and runs
PHPStan against it. Prints per-identifier counts for Campaign A and B.

### `trace.php`

A finding's location is a line in a _compiled_ template, where the offending
expression is usually a loop variable several `foreach` levels below the
property that actually carries the wrong type. This walks it back:

```text
compiled line -> root variable -> foreach bindings in the .latte source
              -> outermost template variable -> the {templateType} View property
```

Findings whose root is not a declared constructor parameter of that View come
out with an empty `property`: they are template locals, `{include}` arguments
or fallback-union globals, and their fix lands somewhere other than a View.
Roughly one finding in six. Treating them as properties inflates per-View
counts.

### `assign.php`

Assigns each traced pair to exactly one fix technique. Pass
`--out=assignment.json` for the per-pair mapping; it is derived data and is
deliberately not committed. The techniques overlap in reality — one chain can contain
a flatten, a loose leaf VO _and_ an `array_merge` — so this is a
single-assignment rule with a deliberate precedence, and its counts mean
"findings this technique resolves", not a partition of the codebase.

Precedence matters. `CheckIntegrityView::$c13yList` reaches its View as
`$c13yResult->c13yList`, which looks like technique 2, but is a flatten one
class upstream; the read-confirmed list is consulted before the `$x->prop`
shape.

### `unflatten.php`

Technique 1's rewriter. Derives the key→property map from the VO's own
`toArray()` body — both shapes: keys in the returned literal, and keys
appended afterwards (`$result['U_DELETE'] = $this->uDelete;`, often inside an
`if`, which is itself the signal that the property is nullable).

```sh
php tools/p58/unflatten.php <Vo.php> <template.latte> <varName>          # dry run
php tools/p58/unflatten.php <Vo.php> <template.latte> <varName> --apply
```

**It fails closed** — audited against double-quoted keys, a missing trailing
comma, dynamic `$bag[$k]` reads and key-prefix collisions; each leaves a
residual and blocks the write.

**But a clean report is not verification.** Two blind spots, by construction:

- _Nested reads._ `$search['filters_views']['last_filters_conf']` becomes
  `$search->filtersViews['last_filters_conf']` and this reports "0 unmapped,
  0 residual" — the inner access survives as an array offset on a VO. That is
  how a real bug reached PHPStan rather than this script.
- _PHP-side reads._ A View's `exposedPageData()`/`exposedStrings()` read the
  same bag and must be updated by hand.

PHPStan after the rewrite is the gate.
