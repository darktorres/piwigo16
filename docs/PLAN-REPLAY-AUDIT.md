# PLAN-REPLAY.md audit — what's actually implemented vs. documented

Evidence-based review of `docs/PLAN-REPLAY.md`'s 33 phases (P0–P32) plus its greenfield
tracks, appendices, and supporting files (`docs/plan/manifest.yaml`, `docs/adr/`, CI
workflows, tests). Every claim below is backed by a command run directly against the
`17.x-rewrite` tree — not the plan doc's own prose, which has been wrong in both
directions (overstating and, once, understating progress). Where the plan already
contained its own prior audit (nine dated `2026-07-13`/`2026-07-17` inline notes), this
file re-verifies rather than re-discovers, and says so.

Status values: `done`, `partial`, `missing`, `diverged` (built, but differently than
documented — doc is stale, not necessarily a real gap).

## Findings, most load-bearing first

1. **No repository in the codebase returns a typed DTO — the whole `Projection`/`Entity`
   + `fromRow()` pattern the plan describes was never built.** `find src/Piwigo -type d
   -iname Projection` → 0 matches anywhere. Every `*Repository.php` sampled (`Image`,
   `Category`, `User`, `Tag`, `Comment`, `Activity`, `Rate`, and others) extends
   `AbstractRepository` and returns raw `array<string, mixed>` from `fetchAssociative()`/
   `fetchAllAssociative()`. This is claimed done by P23's gate text ("all repositories
   return typed DTOs") and separately by P27 ("mixed elimination") — both false. It is
   also functionally P32's "repository restructure" deliverable, unbuilt under a third
   phase's name.
   - **Real, measured symptom, not just architectural debt.** 46 files across nearly
     every domain manually re-implement the same per-field defensive narrowing a
     `fromRow()` factory would centralize (`is_string($row['x']) ? $row['x'] : default`),
     11 of them repositories narrowing their own query output this way. All 46 files were
     read directly (not just grepped) and the pattern is disciplined and correct
     everywhere but one: `BatchManagerUnitPageRenderer.php` (lines 364–374) sits next to a
     **live, self-documented bug** — a comment reading "pre-existing bug, not fixed here"
     explains that `$storage_category_id` is computed from a stale `$row` left over from
     an earlier, unrelated loop, so the STORAGE_CATEGORY admin-UI highlighting feature
     never triggers correctly. The defensive `is_string()` guard next to it exists only to
     keep PHPStan quiet about the generic mixed-row problem while preserving that
     specific, different, already-broken behavior underneath.
   - `grep -c mixed src --include=*.php` → 1093 occurrences (1092 by an independent
     word-boundary recount) across 191 files.
2. **P23's gate "every namespace has tests" is unmet.** 11 of 50 top-level `src/Piwigo/`
   namespaces have zero matching files under `tests/Unit/`: `Audit`, `Caddie`, `Calendar`,
   `Comment`, `Group`, `History`, `Metadata`, `Permission`, `Picture`, `Site`, `Tag`. Of
   these, 10 have coverage via Integration/Contract/Browser suites instead — real
   coverage exists, just not at the Unit level. **`Picture` is the sole exception: zero
   tests of any kind, in any suite.** Its 3 renderer classes (521 lines, including
   permission-checked comment add/edit/delete logic) are exercised only by a
   `VisualRegressionTest.php` pixel-diff snapshot — which catches layout regressions, not
   logic bugs. `PictureCommentRenderer.php` specifically has a documented history of a
   real, previously-shipped bug (a scope-sharing `$edit_comment` issue, fixed during the
   port) — code with a proven bug history currently has no behavioral regression coverage
   at all.
3. **P23's text has two more unflagged, unmet claims**, beyond the DTO and test-coverage
   ones above:
   - "16 Listener/Subscriber classes under `src/Piwigo/Listener/` are event-driven, wired
     here" — `src/Piwigo/Listener/` does not exist. What's actually wired is the
     pre-existing untyped `Piwigo\PluginConfig\EventDispatcher` (`addEventHandler`/
     `triggerChange`/`triggerNotify`, string-keyed event names), carried forward from the
     legacy codebase with 87 real call sites — a working event bus, just not the typed
     PSR-14-style architecture described. Counting distinct event-name string literals
     actually triggered: **141**, ~90% of the doc's claimed 157 — the events themselves
     are substantially real; what's missing is specifically the typed
     Listener/Subscriber/Plugin-contract wrapper around them (same underlying gap as P31).
   - "All 43 column-type Doctrine Migrations applied" — checked one representative column
     from each of the doc's 4 stated categories directly against migration source:
     `categories.commentable` (enum→tinyint) is still `Types::STRING`/raw
     `enum('true','false')`; `search.rules`/`user_infos.preferences`/`config.value`
     (text→JSON) are still `Types::TEXT`; `images.file` (binary→utf8mb4_bin) is still
     plain `Types::STRING`; all 8 "1970-01-01 default → NULL" columns still declare the
     literal `1970-01-01` default. **0 of 5 spot-checked items across all 4 categories
     were applied.** (Two of the doc's 5 listed text→JSON targets are moot since
     `user_cache` itself was deleted in P23's own cache-table rationalization — a
     legitimate reason those two specifically don't need doing, but it doesn't explain
     the other 41.)
   - `include/` and `admin/` are now fully deleted (previously a documented, deliberate
     divergence per the doc's own 2026-07-17 notes — `common.inc.php`'s body moved into
     a real `RequestBootstrap::bootEntryPoint()` method, called directly by every entry
     point; `admin/themes/`'s live assets relocated to `themes/admin/`; both directories
     removed; `tests/Arch/LegacyDirectoryTest.php` updated to assert they're gone). The
     `$GLOBALS`/static-bridge retirement remains deliberately deferred per that same
     2026-07-17 note (its "zero callers remain" premise still doesn't hold in this fork)
     — a real, standing, intentional divergence, not resolved by the directory deletion.
4. **P26 (REST resource layer), P29 (Latte templates), and P30 (Tailwind/CSS) have zero
   foundation, confirmed at the dependency level, not just missing output.**
   `composer.json` has no `latte/latte` and no OpenAPI tooling package
   (`zircote/swagger-php`, `nelmio/api-doc-bundle`, or similar); no CSS/design-token files
   exist anywhere. No `api/v1` reference anywhere in `src/`; the legacy `pwg.*` WS API is
   fully present and active (`WsDefaultMethods.php`, `PwgCore.php`, and more). `find .
   -iname '*.latte'` → 0; `find . -iname '*.tpl'` → 140 Smarty templates still present.
   Nobody has even added the dependency for any of the three.
5. **P24 (Vite + TypeScript conversion) and P25 (inline-JS extraction) were both
   incorrectly treated as done based on config-file presence — neither has real
   deliverable content.** `vite.config.ts`'s `rollupOptions.input` has exactly 2 entries:
   `noop` (explicitly a placeholder) and `vitals` (the P1 RUM remediation), against the
   doc's own stated target of 68 real entries — the config's own comment says "Placeholder
   only — 68 real entries land in P24." All 7 `.ts` files in the repo are tooling config or
   these same 2 entries; zero real application TypeScript exists. P25's "0 `any`" result is
   trivially true because there's no real JS/TS to have found `any` in, not evidence of a
   real reduction. Real, quantified scope still outstanding: 37 inline `<script>` tags
   across 35 `.tpl` files, plus 377 legacy `.js` files untouched. **This is the same
   "tooling exists, content doesn't" failure mode as finding #4** — every `done` verdict
   resting only on config-file presence should be treated as suspect until its content is
   separately checked. (Every phase in this file marked `done` has since had at least one
   round of content-level, not just presence-level, verification — see the phase table.)
6. **P31 (Plugin/Theme contracts) is unbuilt, but not a total void.** No
   `PluginContract`/`ThemeContract` classes exist; `DummyThemeMaintain` still proxies to
   undeclared dynamic theme functions, exactly the fallback P31 is supposed to replace. As
   noted in finding #3, the underlying event mechanism is real and heavily used (141
   distinct events, 87 call sites) — the gap is specifically the typed contract layer
   around already-working infrastructure, not an event system built from scratch.
7. **The Risk register's stated safety net for the plan's single highest-blast-radius
   phase (P17–P23, "664 functions→classes + same-commit `include/` deletion") does not
   exist.** The register names a "test-mode error-drain assertion per request" as the
   mitigation; a separate section ("PHP error log verification," with working code
   samples) and the "What changes" comparison table both independently describe the same
   feature with concrete specifics: `ErrorCollector::drain(): array`, a `TestMode`-gated
   `GET /__test/errors` route, an `assertNoPhpErrors()` integration-test helper, and a
   dedicated `_data/logs/test_errors.log` file that `IntegrationTestCase` truncates in
   `setUp()` and asserts empty in `tearDown()`. Checked every piece directly against the
   real `ErrorCollector.php` (which exists, but only has `collected()`/`reset()`, no
   `drain()`) and `IntegrationTestCase.php` (read in full — no truncation, no assertion):
   **none of the four pieces exist.** The doc states the log-file half specifically "can
   be wired in P0, since `ErrorCollector` is a standalone class" — this was meant to be
   trivial, first-phase work, not something naturally deferred. Given P0–P23 are otherwise
   substantially complete, this is a genuine, well-evidenced miss in the plan's own
   safety-net infrastructure, not a feature deliverable gap like the others above.
8. **P11's "named cache pools" gap was only 1-of-4 closed, contrary to what P23's own text
   implies.** `CachePools.php` exists with `config()`/`permissions()`/`categoryTree()`/
   `tagCloud()` methods (added in P23 batch 2/3 per its own docblock), but only
   `categoryTree()` has a real consumer (`CategoryService`, `CategoryTreeCache`,
   `CategoryCatsRenderer`). `permissions()`, `tagCloud()`, and `config()` have zero
   callers anywhere in `src/` — `PermissionService::getForbiddenCategories()`, the exact
   method the `permissions` pool's own docblock says it replaces, does not call it.
9. **`docs/plan/manifest.yaml`'s phase-status field independently corroborates this
   entire audit — the strongest external validation available.** It marks `P0`–`P23` as
   `status: done` and `P24`–`P32` as `status: planned`, matching this audit's conclusions
   phase-for-phase. Its `sec:` block (65 `SEC-NN` entries) is a rich, already-largely-
   complete, evidence-linked mini-audit in its own right, carrying the same dated,
   code-verified annotation style as the doc's inline notes — almost certainly the same
   underlying 2026-07-13 review recorded in structured form. Spot-checked 3 `status: done`
   SEC entries directly against code (`UserService::registerUser()`'s duplicate-username
   handling, `AuditService::verifyChain()`/`canonicalJson()`, `UploadService::
   sanitizeSvgIfNeeded()`) — all real. A full SEC-by-SEC re-verification would
   substantially duplicate work already done carefully on the record; any future
   security-focused follow-up should start from this file's `sec:` block, which already
   names exact classes/methods and what's real vs. partial vs. deliberately deferred.
   **One real nuance the manifest can't express:** it marks `P23` as flatly `done`, while
   this audit found P23 has the several real unflagged gate failures in finding #3
   alongside its documented deliberate divergences — worth a finer-grained status than
   the manifest's binary field currently allows.
10. **The "Documentation deliverables" table (line 120) is stale and unreliable — its
    `status` column should not be trusted for anything.** It marks `docs/DEVELOPMENT.md`,
    `docs/DEPLOYMENT.md`, and `docs/RUNBOOK.md` as `planned` despite all three existing
    right now, and marks `docs/plan/manifest.yaml`/`tools/plan-lint` as `planned` despite
    both being fully built and CI-wired (finding #9). This reads as a frozen, pre-P0
    snapshot never updated as work landed — unlike the rest of the document, which has
    clearly been actively maintained. Real content behind it: of the 15 referenced
    `docs/*.md` files, 4 exist (`ARCHITECTURE.md`, `DEPLOYMENT.md`, `DEVELOPMENT.md`,
    `RUNBOOK.md`); of the 11 missing, 9 are tied to phases already found `missing`/
    `partial` (expected), but **`docs/CONFIG.md` (P13, done) and `docs/PRIVACY.md`
    (P17–P23, largely done) are genuine gaps** — code landed, documentation didn't.
11. **Phase-numbering itself is not generally drifted — P23 and P24 specifically became
    catch-all commit-tag buckets.** Full `git log` (12,405 commits) shows `(pN)` commit
    tags tracking the doc's phase numbers 1:1 with plausible small counts for P0–P22
    (2–17 commits each). Only `(p23)` (82 commits) and `(p24)` (202 commits) balloon
    disproportionately — that work (DI/DBAL migration, legacy-globals retirement,
    PHPStan/Psalm suppression triage) matches the doc's P23 gap-closure and P27
    type-correctness scope, not the doc's actual P24 ("Vite + TypeScript conversion,"
    which is separately real per finding #5's P24/P25 status). Trust the commit-tag ↔
    doc-phase mapping for P0–P22; for anything tagged `(p23)`/`(p24)`, read phase content
    against evidence, not the tag number.
12. **P32's 6-layer Deptrac model is genuinely implemented**, matching the doc's target
    table by name exactly (L0Data, L1Infrastructure, L2aCoreDomain, L2bExtendedDomain,
    L3Presentation, L4Integration) — real, not aspirational, and running clean (0
    violations). But `vendor/bin/deptrac --no-progress` also reports 1247 "Uncovered"
    classes (~13% of the graph, not assigned to any layer), and the doc's own step 2 (an
    SCC-size + violation-count arch test that fails CI on regression) doesn't exist
    anywhere in `tests/Arch/`. The realistic targets this phase sets ("L2a SCC ≤ 6", "L2b
    SCC = 0") are currently unmeasured, not just unmet — there's no CI signal that would
    catch a regression on either number even though the layer boundaries themselves are
    enforced.
13. **P32's early-landed "web-root isolation" slice is real and precisely accurate.**
    `public/` exists with 27 root entry points (doc says "~26") and all 4 documented
    symlinks with the exact targets described: `dist`, `themes`, `_data/combined`,
    `admin/themes`. The previously web-reachable `upload/`/`galleries/`/`local/` etc. are
    correctly *not* bridged, closing the SEC-33/35/38/47 gap the doc describes. `var/`
    correctly doesn't exist yet — that directory rename is P32-proper scope, still
    unbuilt. Separately, a bug the doc explicitly flagged as needing its own fix
    (`ThemesStandardPagesPageRenderer` storing an absolute filesystem path rendered as an
    `<img src>` URL) has since been fixed — it now stores a disk-relative path resolved
    via `CustomLogoController`, with a comment explaining why. This is the one instance in
    this whole audit of the doc *understating* progress rather than overstating it.
14. **P28's rate limiting, CSP nonce, WebAuthn, and OIDC SSO are confirmed not built** —
    not just undiscovered. `CachePools::rateLimiter()` doesn't exist and the class's own
    docblock says so explicitly ("deliberately not built — genuinely P28 scope, no
    consumer exists anywhere in this codebase yet"). `SecurityHeaderContributor.php`'s
    docblock says "P28 adds nonce-CSP" (future tense). WebAuthn has no scaffolding
    whatsoever — a migration comment initially misread as building `user_passkeys`
    actually explicitly disclaims it, deferring the table to a later phase. OIDC SSO has
    zero trace. WebAuthn/OIDC are marked **T3** (lower-priority) in the doc, distinct from
    the T2 items. A recursive, full-tree search (beyond the original narrower check) also
    confirmed no COOP/COEP headers, no account-locking code, no `Vary: Cookie` header, and
    no rate-limit response headers anywhere.
15. **P8 has a real, worsening state-isolation gap, and worker mode was never
    implemented.** `docker/Caddyfile` has no `worker` directive; no
    `frankenphp_handle_request()` in `index.php` — still classic per-request execution.
    Separately: a 2026-07-13 note counted 13 classes with a `reset()` method, 5
    arch-tested; today **29 classes have `reset()`, only 6 are arch-tested** (via
    `tests/Arch/StructuralTest.php`'s "reset() is only called from tests/" pattern) —
    coverage dropped from 38% to 21% as the codebase grew, because new static-state
    classes keep landing without picking up the existing arch-test pattern.
16. **Two small, real gaps found while spot-checking the Master security checklist,
    otherwise reliable.** SEC-51 ("pin GitHub Actions to commit SHAs") is 100% compliant —
    every one of 106 `uses:` lines across all 5 workflow files is SHA-pinned. SEC-02 ("CLI
    guards on all `tools/*.php` scripts") is not: `tools/build-config-accessors.php` has
    no `PHP_SAPI` check and would execute its logic (regenerating
    `src/Piwigo/Config/Config.php`) under any calling context. Not currently exploitable —
    `tools/` isn't among `public/`'s 4 real symlinks, so it isn't web-reachable under the
    current deployment layout — but it's a real, literal gap against SEC-02's stated
    scope, worth a one-line fix regardless of reachability.
17. **34 `die()` calls remain across 13 files** (`UploadService.php` alone has 9;
    `ImageGd.php`/`PwgImage.php` several each), all inside genuine mid-request
    image-processing failure paths, confirmed by direct read — not test/CLI-only code.
    This is already-tracked debt (a prior "C3 die/exit architecture, deliberately
    deferred, own planning pass needed" item), reconfirmed here from an independent angle
    (a reference-branch baseline comparison showing 0 for `16.x-rewrite`). Not a new gap;
    `Piwigo\Http\ResponseReadyException`'s own docblock already discusses why raw
    `die()`/`exit()` is a problem (skips pending `finally` blocks).

## Phase-by-phase status

| Phase | Status | Notes |
| --- | --- | --- |
| P0 — PHP tooling + baselines | done | `phpstan.neon`/`ecs.php`/`rector.php`/`psalm.xml` present; full-repo PHPStan → 0 errors. |
| P1 — Frontend tooling | done | All 12 sub-items verified at content depth: real `knip` script, real `.size-limit.json`/`commitlint.config.ts`/`lefthook.yml` content, `renovate.json`/`lighthouserc.json`/`.editorconfig` present. `build/vitals.ts` + `VitalsController` (P1's own prior gap) confirmed remediated. |
| P2 — Test harness | done | `.env.example`/`.env.test`, `tests/Browser/`, `tests/Contract/`, `tests/Fixtures/` all real and wired. Route coverage: 21 routes in `config/routes.php` vs. 22 Browser test files — comparable once accounting for broad smoke tests. |
| P3 — CI pipeline | partial | Workflows exist and run every suite (unit, coverage, integration, contract, browser, visual, SBOM, cosign, Scorecard) — all confirmed real. One unmet deferral: no workflow uses `workflow_call`, despite "reusable workflows deferred to P15" — never fulfilled. |
| P4 — Containerization | done | 5 real Dockerfile stages (composer builder, bun frontend, FrankenPHP production as non-root `www-data`, dev, test) plus an Apache fallback stage, verified by reading every stage. |
| P5 — Composer + Rector + PHPStan | done | PHPStan L10, 0 errors, verified live. |
| P6 — PSR-4 namespace migration | done | `composer.json` PSR-4 mapping verified; 0 files under `src/Piwigo/` with a class/interface/trait/enum missing a matching namespace. |
| P7 — Kernel + boot skeleton | done | `Kernel.php` (89 lines) has real `boot()`/`container()`/`isBooted()`/`reset()`, not a stub. |
| P8 — DI container | done, with open gaps | See finding #15 (worker mode never built; state-isolation arch-test coverage worsening, 38%→21%). |
| P9 — PSR-15 middleware + routing | done | CSRF hardening (sha256 + `hash_equals()`) confirmed fixed, with an in-code docblock crediting the remediation. |
| P10 — Observability | done | Monolog, Sentry, and `ServerTiming`/`ServerTimingMiddleware` all confirmed real. |
| P11 — Cache + session + messenger | partial | See finding #8 (named cache pools 1-of-4 wired to a real consumer). |
| P12 — CLI tool | mostly done | 3 of 4 originally-missing maintenance commands now built and registered; `maintenance:repair-db` remains, with an in-code comment confirming it's deliberately pending a backing service. |
| P13 — Config service | done | `$conf`/`Config` desync gap fully closed — `global $conf` count is 0, and all 5 previously-unverified `$conf[` files (`RequestBootstrap.php`, `WsDefaultMethods.php`, `UserRepository.php`, `PasswordService.php`, `ConfigurationSubController.php`) are confirmed clean (comments, doc strings, or the same legitimate local-shadow pattern already reviewed for `UserListPageRenderer::webmasterIdIsLocal()`). |
| P14 — DB layer + Doctrine ORM | diverged | Only `ConfigRepository` is a real `ServiceEntityRepository`; every other repository uses the `AbstractRepository` DBAL shim the doc says should only exist for legacy code — see finding #1. |
| P15 — Schema migration + multi-provider | done | `install/schema/` has real `mysql.sql`, `mariadb.sql`, and `pgsql.sql`. |
| P16 — Typed facades + constants retirement | done | Only 6 real `define()` calls exist anywhere, both inside escaped string literals written into a *generated* config file — not live code. |
| P17 — Domain tier 1 | diverged | Domain directories present; DTO pattern absent, see finding #1. |
| P18 — Domain tier 2 | diverged | Same — domain dirs present, DTO pattern absent. |
| P19 — Domain tier 3 | diverged | Same — `ImageRepository` directly confirmed still raw-array-returning. |
| P20 — Domain tier 4 | diverged | Same DTO gap; the `SectionInitializer`/`GalleryController` absorption gap from a 2026-07-13 note is confirmed remediated. |
| P21 — Admin controller migration | done | Admin god-class decomposition confirmed: no monolithic controller exists by any of the reference's names; split into sub-controllers and PageRenderers, largest 276 lines. |
| P22 — Frontend controller migration | done | 60 files (21 frontend + 39 admin sub-controllers); sampled 5 for real handler logic (38–660 lines each), not placeholders. |
| P23 — Legacy deletion & cleanup | partial — `include/`/`admin/` deletion now done; 3 unflagged gaps + 1 standing deliberate divergence remain | `include/` and `admin/` are fully deleted (`common.inc.php` inlined into `RequestBootstrap::bootEntryPoint()`, `admin/themes/` relocated to `themes/admin/`). `$GLOBALS`/static-bridge retirement remains deliberately deferred per the doc's own 2026-07-17 note — a real, intentional divergence, not a gap. See findings #1–#3 for 3 real, previously-unflagged gate failures in this phase's own stated text (typed DTOs, per-namespace tests, Listener/Subscriber classes, 43 column migrations) that are still open. |
| P24 — Vite + TypeScript conversion | missing | See finding #5. |
| P25 — Inline JS extraction | missing | See finding #5; depends on P24. |
| P26 — REST resource layer + OpenAPI | missing | See finding #4. |
| P27 — Type correctness + mixed elimination | missing | Same DTO gap as finding #1; `RequestCache` with `@template T` doesn't exist either. |
| P28 — Security hardening | partial | Baseline headers real; rate limiting, CSP nonce, COOP/COEP, account-locking, WebAuthn, OIDC all confirmed unbuilt — see finding #14. |
| P29 — Template migration (Smarty → Latte) | missing | See finding #4. |
| P30 — CSS modernization + Tailwind | missing | See finding #4. |
| P31 — Plugin/Theme contracts | missing, not a void | See finding #6. |
| P32 — Layer decoupling + repository restructure | partial | Layer model real (finding #12); web-root isolation slice real (finding #13); repository restructure is the same DTO gap as finding #1. |
| T3·WEB / T3·AI (greenfield tracks) | missing | Correctly blocked — dependencies (P24/P26/P29/P30) are themselves missing. No `JsonLdService`/`OpenGraphService`/`ViteManifest`/`ImageInsight`/MCP/ActivityPub classes; no `VECTOR(512)` embedding column in any migration. |
| Legacy import (`bin/piwigo import:legacy`) | missing | Not blocked — prerequisites (P15, P23) are met, but nothing is built. The one item in the greenfield/adoption territory whose absence isn't structurally explained. |

## Remaining unverified

- **A full line-by-line read of all 214 test files' individual assertions** was not done —
  four independent partial checks (assertion count, namespace-coverage cross-reference, a
  weak/filler-assertion sweep, CI-wiring confirmation) found nothing wrong, but this
  specific maximal version of the check remains open.
- **`docs/plan/manifest.yaml`'s binary `P23: done` status** should be reconciled with this
  audit's finding that P23 has real unflagged gate failures (finding #3) alongside its
  documented divergences — worth a note back to the manifest itself if anyone acts on
  these findings, not just this file.
- **Glossary, "Performance considerations," and "Migration path" sections of
  `PLAN-REPLAY.md`** were not individually verified — low expected yield based on the
  pattern of what nearby, already-checked sections contained (framing/reference prose,
  not new implementation claims), but not ruled out by direct reading.
- **Deciding what to actually fix** from the `missing`/`diverged`/`partial` rows above is
  a separate, explicitly out-of-scope follow-up — this file is a map, not a remediation
  plan.

## Methodology notes worth keeping in mind

- **Sandboxed `grep` produced both a false positive and a false negative in this
  environment on `$`-containing patterns** (e.g. `is_string($row[`) — confirmed present via
  direct file reads but returning 0 matches via `grep`, in both double- and single-quoted
  forms. Cross-check any suspicious zero-match `grep` result on a `$`-containing pattern
  with Python or a direct read before treating it as an absence. A prior grep also produced
  a false *positive* by matching `define()` mentions inside comments/docblocks rather than
  real statements — anchor patterns to actual code, not prose that discusses the pattern.
- **"The config/dependency file exists" is not evidence a phase's deliverable content
  exists.** This was the single biggest source of correction in this audit (findings #4 and
  #5) — `vite.config.ts` being present said nothing about whether it had 68 real entries or
  2 placeholders. Every `done` verdict in the phase table above rests on content actually
  read, not just file/directory presence.
