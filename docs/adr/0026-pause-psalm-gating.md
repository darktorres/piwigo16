# ADR-0026: Pause Psalm gating until after typed/namespaced refactoring

## Status

Accepted (decided P5)

## Context

Psalm was installed and baselined at P0 alongside PHPStan, gating both CI
and the `pre-push` hook from P0 onward. During P5's PHPMailer → Symfony
Mailer rewrite, Psalm began reproducibly failing to resolve calls to two
genuinely existing, correctly-defined functions
(`trigger_change()` in `include/functions_plugins.inc.php`,
`pwg_send_mail_test()` — defined later in the same file as its call site)
from `include/functions_mail.inc.php`, reporting them as
`UndefinedFunction`.

Investigated properly rather than working around it blindly:

- Confirmed the code itself is correct: `php -l` clean, runtime
  `function_exists()` true for both functions when the files are loaded
  directly, and PHPStan (level 0, same codebase, same files) reports zero
  errors.
- Ruled out Psalm's file-storage cache as the direct cause of *this specific*
  symptom (distinct from the separate, already-documented
  MissingClassConstType/InvalidDocblock cache-staleness mechanism found
  earlier in P5) — the failure reproduced identically across multiple
  consecutive runs with no code changes in between.
- Ruled out a parallel-worker race: `--threads=1` (single-threaded,
  ~4x slower) reproduced the identical failure.
- This points to a deeper limitation in how Psalm's project-wide global
  function index resolves calls in a large, non-namespaced, purely
  procedural legacy codebase at this scale (320+ scanned files, thousands
  of loose `function foo() {}` declarations with no class or namespace
  boundary) — not a bug in the code being analyzed.

## Decision

Stop gating on Psalm — remove it from `.github/workflows/ci.yml` and
`lefthook.yml`'s `pre-push` hook, and drop it from `composer.json`'s
`analyse` composite script (the standalone `analyse:psalm` script stays,
for optional manual runs). PHPStan remains the sole blocking static-analysis
gate.

Resume Psalm gating once P6 (PSR-4 namespace migration) and the P17-P23
service-layer refactoring have moved enough of the codebase from loose
global functions into namespaced classes that Psalm's scanner has the
structure it needs to resolve symbols reliably. `psalm.xml`,
`psalm-baseline.xml`, and the `vimeo/psalm` dependency all stay in the repo
(dormant, not deleted) so re-enabling later is a config change, not a
from-scratch setup.

## Consequences

- Any phase between now and the resume point that references "Psalm 0
  errors" as part of its own gate (several do, written before this ADR)
  should treat that requirement as superseded by this decision, not
  independently re-litigated — cite this ADR rather than re-investigating
  the same symptom.
- `psalm-baseline.xml` will drift out of sync with the codebase while
  Psalm isn't running. That's expected and fine — the baseline gets
  regenerated from scratch when Psalm gating resumes, the same way P5
  itself twice regenerated it from a clean slate; there's no ongoing
  maintenance burden while paused.
- PHPStan alone is a real gate reduction (Psalm and PHPStan catch
  meaningfully different issue classes), accepted deliberately as the
  trade-off for not fighting a tool that doesn't fit the codebase's current
  shape.
